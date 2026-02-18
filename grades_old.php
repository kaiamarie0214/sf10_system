<?php
include "../includes/db.php";
session_start();

// Set timezone to Manila
date_default_timezone_set('Asia/Manila');

// Handle form submission BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grades'])) {
    // Get current user role
    $current_user_role = $_SESSION['user']['role'];
    
    // Check if is_transfer column exists
    $columns = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'is_transfer'");
    $has_is_transfer = ($columns && $columns->num_rows > 0);
    
    // Get school_year and grade_level from the selected school_attended record
    if ($has_is_transfer) {
        $school_info = $conn->query("SELECT school_year, grade_level, is_transfer, adviser_name
                                     FROM schools_attended 
                                     WHERE id = {$_POST['school_attended_id']}")->fetch_assoc();
        $is_transfer = $school_info['is_transfer'] ?? 0;
        
        // Auto-detect transfer status if not explicitly set
        if (!$is_transfer && !empty($school_info['adviser_name'])) {
            $adviser_check = $conn->query("SELECT id FROM users WHERE full_name = '" . $conn->real_escape_string($school_info['adviser_name']) . "'")->num_rows;
            $is_transfer = ($adviser_check == 0);
        }
    } else {
        $school_info = $conn->query("SELECT school_year, grade_level, adviser_name
                                     FROM schools_attended 
                                     WHERE id = {$_POST['school_attended_id']}")->fetch_assoc();
        $is_transfer = 0;
        
        // Auto-detect transfer status
        if (!empty($school_info['adviser_name'])) {
            $adviser_check = $conn->query("SELECT id FROM users WHERE full_name = '" . $conn->real_escape_string($school_info['adviser_name']) . "'")->num_rows;
            $is_transfer = ($adviser_check == 0);
        }
    }
    
    // SERVER-SIDE QUARTER LOCK VALIDATION
    // Only apply quarter locks to teachers (not admins) and only for regular students (not transfer students)
    if ($current_user_role !== 'admin' && !$is_transfer) {
        // Get current quarter locks
        $lock_query = $conn->query("SELECT quarter, locked FROM quarter_locks WHERE school_attended_id IS NULL");
        $locked_quarters = [];
        
        if ($lock_query) {
            while ($lock_row = $lock_query->fetch_assoc()) {
                if ($lock_row['locked']) {
                    $locked_quarters[] = $lock_row['quarter'];
                }
            }
        }
        
        // Check if any NEW or CHANGED grades are being submitted for locked quarters
        if (!empty($locked_quarters)) {
            // Get existing grades from database
            $existing_grades = [];
            $existing_query = $conn->prepare("SELECT subject_id, quarter, grade FROM grades 
                                              WHERE student_id = ? AND school_attended_id = ?");
            $existing_query->bind_param("ii", $_POST['student_id'], $_POST['school_attended_id']);
            $existing_query->execute();
            $result = $existing_query->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $existing_grades[$row['subject_id']][$row['quarter']] = $row['grade'];
            }
            
            $violations = [];
            
            foreach ($_POST['grades'] as $subject_id => $data) {
                for ($q = 1; $q <= 4; $q++) {
                    // Check if this quarter is locked
                    if (in_array($q, $locked_quarters)) {
                        $quarterKey = 'q' . $q;
                        
                        if (array_key_exists($quarterKey, $data)) {
                            $new_value = trim($data[$quarterKey]);
                            
                            // Get existing value (if any)
                            $old_value = isset($existing_grades[$subject_id][$q]) ? trim($existing_grades[$subject_id][$q]) : '';
                            
                            // Only reject if value is NEW or CHANGED
                            // Allow if: empty submission, or same as existing value
                            if ($new_value !== '') {
                                // Compare with existing - only block if different (accounting for decimal precision)
                                if ($old_value === '' || abs(floatval($old_value) - floatval($new_value)) > 0.001) {
                                    $violations[] = "Quarter $q (trying to change from '$old_value' to '$new_value')";
                                    break 2; // Exit both loops on first violation
                                }
                            }
                        }
                    }
                }
            }
            
            // If violations found, reject the entire submission
            if (!empty($violations)) {
                // Set error in session
                $_SESSION['error_message'] = "❌ Cannot save grades! " . implode(', ', $violations) . " is currently LOCKED by administrator. You cannot add or modify grades in locked quarters. Please contact an administrator to unlock the quarter.";
                
                // Add a flag to reload grades from database (clear cached form data)
                $_SESSION['reload_grades'] = true;
                $_SESSION['reload_student_id'] = $_POST['student_id'];
                $_SESSION['reload_school_id'] = $_POST['school_attended_id'];
                
                // Redirect back to grades page with timestamp to prevent caching
                header("Location: " . $_SERVER['PHP_SELF'] . "?t=" . time());
                exit(); // FORCE STOP - DO NOT CONTINUE
            }
        }
    }
    
    $school_year = $school_info['school_year'];
    $grade_level = $school_info['grade_level'];

    // Permission check: if current user is a teacher ensure they still own this school_attended record
    if ($current_user_role !== 'admin') {
        $sa_id = intval($_POST['school_attended_id']);

        // Check for active column
        $col_check = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'active'");
        $has_active = ($col_check && $col_check->num_rows > 0);

        $sa_fields = 'grade_level, section, school_year';
        if ($has_active) $sa_fields .= ', active';

        $sa_stmt = $conn->prepare("SELECT {$sa_fields} FROM schools_attended WHERE id = ? LIMIT 1");
        $sa_stmt->bind_param("i", $sa_id);
        $sa_stmt->execute();
        $sa_row = $sa_stmt->get_result()->fetch_assoc();

        if (!$sa_row) {
            $_SESSION['error_message'] = 'Cannot save grades: the selected enrollment record no longer exists.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?t=" . time());
            exit();
        }

        if ($has_active && empty($sa_row['active'])) {
            $_SESSION['error_message'] = 'Cannot save grades: this student is no longer assigned to your class.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?t=" . time());
            exit();
        }

        // Get teacher's adviser assignment
        $ta = $conn->prepare("SELECT grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser' LIMIT 1");
        $ta->bind_param("i", $_SESSION['user']['id']);
        $ta->execute();
        $ta_row = $ta->get_result()->fetch_assoc();

        if (!$ta_row) {
            $_SESSION['error_message'] = 'Cannot save grades: you are not assigned as an adviser.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?t=" . time());
            exit();
        }

        // Match grade level
        if (intval($ta_row['grade_level']) !== intval($sa_row['grade_level'])) {
            $_SESSION['error_message'] = 'Cannot save grades: this student is not in your grade level.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?t=" . time());
            exit();
        }

        // If teacher has a section assigned, require section match. If section empty, teacher covers whole grade.
        $ta_section = trim((string)($ta_row['section'] ?? ''));
        $sa_section = trim((string)($sa_row['section'] ?? ''));
        if ($ta_section !== '' && strcasecmp($ta_section, $sa_section) !== 0) {
            $_SESSION['error_message'] = 'Cannot save grades: this student is not in your section.';
            header("Location: " . $_SERVER['PHP_SELF'] . "?t=" . time());
            exit();
        }
    }
    
    $all_final_ratings = [];
    $ga_subject_id = $conn->query("SELECT id FROM subjects WHERE subject_name='General Average' LIMIT 1")->fetch_assoc()['id'];
    
    $grades_entered = 0;

    foreach ($_POST['grades'] as $subject_id => $data) {
        // Handle subject name updates (only for transfer students)
        if (isset($data['subject_name'])) {
            $new_subject_name = trim($data['subject_name']);

            // Check the default subject name from subjects table
            $current_subject = $conn->query("SELECT subject_name FROM subjects WHERE id = $subject_id")->fetch_assoc();

            // Check if custom subjects table exists
            $custom_table_exists = $conn->query("SHOW TABLES LIKE 'student_custom_subjects'")->num_rows > 0;

            // If custom table exists, see if a custom row already exists for this student/school/subject
            $existing_custom = null;
            if ($custom_table_exists) {
                $esc_student = intval($_POST['student_id']);
                $esc_school = intval($_POST['school_attended_id']);
                $esc_subject = intval($subject_id);
                $row = $conn->query("SELECT custom_subject_name FROM student_custom_subjects WHERE student_id = $esc_student AND school_attended_id = $esc_school AND subject_id = $esc_subject LIMIT 1");
                if ($row && $row->num_rows > 0) {
                    $existing_custom = $row->fetch_assoc()['custom_subject_name'];
                }
            }

            // ONLY save custom subject names for transfer students
            // Regular students' subject names are managed through "Manage School Subject Format" modal only
            if ($is_transfer && $custom_table_exists) {
                // If user reverted the name back to the default, remove any existing custom override
                if ($new_subject_name === $current_subject['subject_name']) {
                    if ($existing_custom !== null) {
                        $del_sql = "DELETE FROM student_custom_subjects WHERE student_id = ? AND school_attended_id = ? AND subject_id = ?";
                        $del_stmt = $conn->prepare($del_sql);
                        $del_stmt->bind_param("iii", $_POST['student_id'], $_POST['school_attended_id'], $subject_id);
                        $del_stmt->execute();
                    }
                } else {
                    // For transfer students: save custom subject name specific to this student
                    $stmt = $conn->prepare("INSERT INTO student_custom_subjects 
                                          (student_id, school_attended_id, subject_id, custom_subject_name)
                                          VALUES (?, ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE custom_subject_name = VALUES(custom_subject_name)");
                    $stmt->bind_param("iiis", $_POST['student_id'], $_POST['school_attended_id'], $subject_id, $new_subject_name);
                    $stmt->execute();
                }
            }
            // Note: For regular students, subject name changes are ignored here
            // Use "Manage School Subject Format" to change grade-level subject names
        }
        
        $final_rating = !empty($data['final_rating']) ? round(floatval($data['final_rating'])) : null;
        $remarks = !empty($data['remarks']) ? $data['remarks'] : null;

        if ($final_rating && $subject_id != $ga_subject_id) {
            $all_final_ratings[] = $final_rating;
        }

        for ($q = 1; $q <= 4; $q++) {
            $grade = !empty($data['q' . $q]) ? round(floatval($data['q' . $q])) : null;
            
            if ($grade !== null) {
                $grades_entered++;
            }
            
            $stmt = $conn->prepare("INSERT INTO grades
                (student_id, school_attended_id, subject_id, quarter, grade, final_rating, remarks, is_general_average, teacher_id, school_year)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
                ON DUPLICATE KEY UPDATE
                grade = VALUES(grade), final_rating = VALUES(final_rating), remarks = VALUES(remarks)");
            $stmt->bind_param(
                "iiidsssis",
                $_POST['student_id'],
                $_POST['school_attended_id'],
                $subject_id,
                $q,
                $grade,
                $final_rating,
                $remarks,
                $_SESSION['user']['id'],
                $school_year
            );
            $stmt->execute();
        }
    }
    // Compute and persist General Average (server-side) so preview can read stored values
    if (!empty($ga_subject_id)) {
        // Subjects to skip when computing GA (matches client-side skipIds)
        $skipIds = [9, 10, 11, 12];

        $quarterAverages = [];
        for ($q = 1; $q <= 4; $q++) {
            $sum = 0;
            $count = 0;
            foreach ($_POST['grades'] as $subject_id => $data) {
                $sid = intval($subject_id);
                if (in_array($sid, $skipIds)) continue;
                if (isset($data['q' . $q]) && trim($data['q' . $q]) !== '') {
                    $sum += round(floatval($data['q' . $q]));
                    $count++;
                }
            }
            $quarterAverages[$q] = ($count > 0) ? round($sum / $count) : null;
        }

        // Final GA: average of final ratings of non-GA subjects (we collected these earlier)
        $ga_final = null;
        if (!empty($all_final_ratings)) {
            $ga_final = round(array_sum($all_final_ratings) / count($all_final_ratings));
        }

        $ga_remarks = ($ga_final !== null) ? ($ga_final >= 75 ? 'Passed' : 'Failed') : null;

        // Insert/update General Average rows (set is_general_average = 1)
        for ($q = 1; $q <= 4; $q++) {
            $gradeVal = ($quarterAverages[$q] !== null) ? $quarterAverages[$q] : null;
            $stmt = $conn->prepare("INSERT INTO grades
                (student_id, school_attended_id, subject_id, quarter, grade, final_rating, remarks, is_general_average, teacher_id, school_year)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                grade = VALUES(grade), final_rating = VALUES(final_rating), remarks = VALUES(remarks), is_general_average = VALUES(is_general_average)");
            $is_ga = 1;
            $stmt->bind_param(
                "iiidsssiis",
                $_POST['student_id'],
                $_POST['school_attended_id'],
                $ga_subject_id,
                $q,
                $gradeVal,
                $ga_final,
                $ga_remarks,
                $is_ga,
                $_SESSION['user']['id'],
                $school_year
            );
            $stmt->execute();
        }
    }

    // Save remedial classes
    if (isset($_POST['remedial']) && is_array($_POST['remedial'])) {
        // Detect if remedial_classes has school_attended_id column
        $col_check = $conn->query("SHOW COLUMNS FROM remedial_classes LIKE 'school_attended_id'");
        $has_school_attended = ($col_check && $col_check->num_rows > 0);

        // First, delete existing remedial classes for this student and scope (prefer school_attended_id)
        if ($has_school_attended) {
            $delete_stmt = $conn->prepare("DELETE FROM remedial_classes WHERE student_id = ? AND school_attended_id = ?");
            $delete_stmt->bind_param("ii", $_POST['student_id'], $_POST['school_attended_id']);
        } else {
            $delete_stmt = $conn->prepare("DELETE FROM remedial_classes WHERE student_id = ? AND school_year = ?");
            $delete_stmt->bind_param("is", $_POST['student_id'], $school_year);
        }
        $delete_stmt->execute();
        
        // Insert new remedial classes
        foreach ($_POST['remedial'] as $remedial_data) {
            // Only save if learning_area is selected
            if (!empty($remedial_data['learning_area'])) {
                $learning_area = trim($remedial_data['learning_area']);
                $final_rating = !empty($remedial_data['final_rating']) ? round(floatval($remedial_data['final_rating'])) : null;
                $remedial_mark = !empty($remedial_data['remedial_class_mark']) ? round(floatval($remedial_data['remedial_class_mark'])) : null;
                $recomputed = !empty($remedial_data['recomputed_final_grade']) ? round(floatval($remedial_data['recomputed_final_grade'])) : null;
                $remarks = !empty($remedial_data['remarks']) ? trim($remedial_data['remarks']) : null;
                $conducted_from = !empty($remedial_data['conducted_from']) ? $remedial_data['conducted_from'] : null;
                $conducted_to = !empty($remedial_data['conducted_to']) ? $remedial_data['conducted_to'] : null;

                if ($has_school_attended) {
                    $stmt = $conn->prepare("INSERT INTO remedial_classes 
                        (student_id, school_attended_id, school_year, learning_area, final_rating, remedial_class_mark, 
                         recomputed_final_grade, remarks, conducted_from, conducted_to)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iissiiisss", 
                        $_POST['student_id'],
                        $_POST['school_attended_id'],
                        $school_year,
                        $learning_area,
                        $final_rating,
                        $remedial_mark,
                        $recomputed,
                        $remarks,
                        $conducted_from,
                        $conducted_to
                    );
                } else {
                    $stmt = $conn->prepare("INSERT INTO remedial_classes 
                        (student_id, school_year, learning_area, final_rating, remedial_class_mark, 
                         recomputed_final_grade, remarks, conducted_from, conducted_to)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("issiiisss", 
                        $_POST['student_id'], 
                        $school_year, 
                        $learning_area, 
                        $final_rating, 
                        $remedial_mark, 
                        $recomputed, 
                        $remarks, 
                        $conducted_from, 
                        $conducted_to
                    );
                }
                $stmt->execute();
            }
        }
    }
    
    // Load logger for activity logging
    include_once "../includes/logger.php";
    
    // Log the grade entry activity
    if ($grades_entered > 0) {
        $student_info = $conn->query("SELECT CONCAT(first_name, ' ', last_name) as name FROM students WHERE id = {$_POST['student_id']}")->fetch_assoc();
        logActivity($conn, $_SESSION['user']['id'], 'GRADE_ENTRY', 'grades', $_POST['student_id'], 
                   "Entered $grades_entered grade(s) for student: {$student_info['name']} (SY: {$school_year})");
    }

    $success = true;
    
    // Store student ID and school_attended_id for cross-tab notification
    $_SESSION['grades_saved_for_student'] = $_POST['student_id'];
    $_SESSION['grades_saved_for_school'] = $_POST['school_attended_id'];
}

// Check and apply auto-locks/unlocks based on scheduled times
function checkAutoLocks($conn) {
    $current_time = date('Y-m-d H:i:s');
    
    // Check auto-lock times
    $auto_lock_query = $conn->query("SELECT quarter, auto_lock_time FROM quarter_auto_locks 
                                     WHERE school_attended_id IS NULL 
                                     AND auto_lock_time <= '$current_time'");
    
    if ($auto_lock_query) {
        while ($row = $auto_lock_query->fetch_assoc()) {
            $quarter = $row['quarter'];
            
            // Check if quarter is already locked
            $check = $conn->query("SELECT locked FROM quarter_locks 
                                  WHERE school_attended_id IS NULL AND quarter = $quarter");
            
            if ($check && $check->num_rows > 0) {
                $lock_status = $check->fetch_assoc();
                
                // If not already locked, lock it now
                if (!$lock_status['locked']) {
                    $conn->query("UPDATE quarter_locks SET locked = 1 
                                 WHERE school_attended_id IS NULL AND quarter = $quarter");
                }
            } else {
                // Create lock entry
                $conn->query("INSERT INTO quarter_locks (school_attended_id, quarter, locked) 
                             VALUES (NULL, $quarter, 1)");
            }
        }
    }
    
    // Check auto-unlock times
    $auto_unlock_query = $conn->query("SELECT quarter, auto_unlock_time FROM quarter_auto_unlocks 
                                       WHERE school_attended_id IS NULL 
                                       AND auto_unlock_time <= '$current_time'");
    
    if ($auto_unlock_query) {
        while ($row = $auto_unlock_query->fetch_assoc()) {
            $quarter = $row['quarter'];
            
            // Unlock the quarter
            $conn->query("UPDATE quarter_locks SET locked = 0 
                         WHERE school_attended_id IS NULL AND quarter = $quarter");
        }
    }
}
// Execute auto-lock check on every page load
checkAutoLocks($conn);

// AJAX endpoint for getting quarter locks (global for all students)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_quarter_locks') {
    header('Content-Type: application/json');
    
    try {
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'quarter_locks'");
        if ($tableCheck->num_rows === 0) {
            echo json_encode([
                'success' => false, 
                'error' => 'quarter_locks table does not exist. Please run the database setup script.',
                'locks' => ['q1' => false, 'q2' => false, 'q3' => false, 'q4' => false],
                'auto_locks' => [],
                'auto_unlocks' => []
            ]);
            exit;
        }
        
        // Get global lock status (NULL = global for all students)
        $result = $conn->query("SELECT quarter, locked FROM quarter_locks WHERE school_attended_id IS NULL");
        
        $locks = ['q1' => false, 'q2' => false, 'q3' => false, 'q4' => false];
        while ($row = $result->fetch_assoc()) {
            $locks['q' . $row['quarter']] = (bool)$row['locked'];
        }
        
        // Get auto-lock times (global)
        $auto_locks = [];
        $result = $conn->query("SELECT quarter, auto_lock_time FROM quarter_auto_locks WHERE school_attended_id IS NULL");
        while ($row = $result->fetch_assoc()) {
            // Convert MySQL datetime to HTML5 datetime-local format
            $auto_locks['q' . $row['quarter']] = date('Y-m-d\TH:i', strtotime($row['auto_lock_time']));
        }
        
        // Get auto-unlock times (global)
        $auto_unlocks = [];
        $result = $conn->query("SELECT quarter, auto_unlock_time FROM quarter_auto_unlocks WHERE school_attended_id IS NULL");
        while ($row = $result->fetch_assoc()) {
            // Convert MySQL datetime to HTML5 datetime-local format
            $auto_unlocks['q' . $row['quarter']] = date('Y-m-d\TH:i', strtotime($row['auto_unlock_time']));
        }
        
        echo json_encode([
            'success' => true, 
            'locks' => $locks, 
            'auto_locks' => $auto_locks,
            'auto_unlocks' => $auto_unlocks
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage(), 
            'locks' => ['q1' => false, 'q2' => false, 'q3' => false, 'q4' => false],
            'auto_locks' => [],
            'auto_unlocks' => []
        ]);
    }
    exit;
}

// AJAX endpoint for getting auto-lock times only (for real-time checker)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_auto_lock_times') {
    header('Content-Type: application/json');
    
    try {
        // Get auto-lock times (global)
        $auto_locks = [];
        $result = $conn->query("SELECT quarter, auto_lock_time FROM quarter_auto_locks WHERE school_attended_id IS NULL");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $auto_locks['q' . $row['quarter']] = $row['auto_lock_time'];
            }
        }
        
        // Get auto-unlock times (global)
        $auto_unlocks = [];
        $result = $conn->query("SELECT quarter, auto_unlock_time FROM quarter_auto_unlocks WHERE school_attended_id IS NULL");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $auto_unlocks['q' . $row['quarter']] = $row['auto_unlock_time'];
            }
        }
        
        echo json_encode([
            'success' => true,
            'auto_locks' => $auto_locks,
            'auto_unlocks' => $auto_unlocks
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// AJAX endpoint for toggling quarter lock (global)
if (isset($_POST['ajax']) && $_POST['ajax'] === 'toggle_quarter_lock') {
    header('Content-Type: application/json');
    
    try {
        $user = $_SESSION['user'];
        if ($user['role'] !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $quarter = intval($_POST['quarter']);
        $locked = intval($_POST['locked']);
        
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'quarter_locks'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'quarter_locks table does not exist']);
            exit;
        }
        
        // Check if record exists (global lock uses NULL)
        $check = $conn->query("SELECT id FROM quarter_locks WHERE school_attended_id IS NULL AND quarter = $quarter");
        if (!$check) {
            echo json_encode(['success' => false, 'message' => 'Query error: ' . $conn->error]);
            exit;
        }
        
        $exists = $check->fetch_assoc();
        
        if ($exists) {
            // Update existing record
            $result = $conn->query("UPDATE quarter_locks SET locked = $locked WHERE school_attended_id IS NULL AND quarter = $quarter");
        } else {
            // Insert new record (NULL for global lock)
            $result = $conn->query("INSERT INTO quarter_locks (school_attended_id, quarter, locked) VALUES (NULL, $quarter, $locked)");
        }
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        
        // Log the activity
        include "../includes/logger.php";
        $action = $locked ? 'locked' : 'unlocked';
        logActivity($conn, $user['id'], 'UPDATE', 'quarter_locks', null, 
                   "Quarter $quarter $action globally for all students");
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    }
    exit;
}

// AJAX endpoint for setting auto-lock time (global)
if (isset($_POST['ajax']) && $_POST['ajax'] === 'set_auto_lock_time') {
    header('Content-Type: application/json');
    
    $user = $_SESSION['user'];
    if ($user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $quarter = intval($_POST['quarter']);
    $lock_time = $_POST['lock_time'];
    
    // Check if record exists (global lock uses NULL)
    $check = $conn->query("SELECT id FROM quarter_auto_locks WHERE school_attended_id IS NULL AND quarter = $quarter");
    $exists = $check->fetch_assoc();
    
    $result = false;
    if ($exists) {
        // Update existing record
        $result = $conn->query("UPDATE quarter_auto_locks SET auto_lock_time = '$lock_time' WHERE school_attended_id IS NULL AND quarter = $quarter");
    } else {
        // Insert new record (NULL for global)
        $result = $conn->query("INSERT INTO quarter_auto_locks (school_attended_id, quarter, auto_lock_time) VALUES (NULL, $quarter, '$lock_time')");
    }
    
    if ($result) {
        include_once "../includes/logger.php";
        logActivity($conn, $user['id'], 'UPDATE', 'quarter_auto_locks', NULL, 
                   "Auto-lock scheduled for Quarter $quarter at $lock_time");
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// AJAX endpoint for setting auto-unlock time
if (isset($_POST['ajax']) && $_POST['ajax'] === 'set_auto_unlock_time') {
    header('Content-Type: application/json');
    
    $user = $_SESSION['user'];
    if ($user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $quarter = intval($_POST['quarter']);
    $unlock_time = $_POST['unlock_time'];
    
    // Check if record exists (global lock uses NULL)
    $check = $conn->query("SELECT id FROM quarter_auto_unlocks WHERE school_attended_id IS NULL AND quarter = $quarter");
    $exists = $check->fetch_assoc();
    
    $result = false;
    if ($exists) {
        // Update existing record
        $result = $conn->query("UPDATE quarter_auto_unlocks SET auto_unlock_time = '$unlock_time' WHERE school_attended_id IS NULL AND quarter = $quarter");
    } else {
        // Insert new record (NULL for global)
        $result = $conn->query("INSERT INTO quarter_auto_unlocks (school_attended_id, quarter, auto_unlock_time) VALUES (NULL, $quarter, '$unlock_time')");
    }
    
    if ($result) {
        include_once "../includes/logger.php";
        logActivity($conn, $user['id'], 'UPDATE', 'quarter_auto_unlocks', NULL, 
                   "Auto-unlock scheduled for Quarter $quarter at $unlock_time");
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// AJAX endpoint for clearing auto-lock schedule
if (isset($_POST['ajax']) && $_POST['ajax'] === 'clear_auto_lock') {
    header('Content-Type: application/json');
    
    $user = $_SESSION['user'];
    if ($user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $quarter = intval($_POST['quarter']);
    
    $result = $conn->query("DELETE FROM quarter_auto_locks WHERE school_attended_id IS NULL AND quarter = $quarter");
    
    if ($result) {
        include "../includes/logger.php";
        logActivity($conn, $user['id'], 'DELETE', 'quarter_auto_locks', null, 
                   "Auto-lock schedule cleared globally for Quarter $quarter");
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// AJAX endpoint for getting subject names for a specific student/school record
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_subject_names') {
    header('Content-Type: application/json');
    
    $student_id = intval($_GET['student_id']);
    $school_attended_id = intval($_GET['school_attended_id']);
    
    // Get all subjects with appropriate names
    $subjects_result = $conn->query("SELECT id, subject_name FROM subjects ORDER BY id");
    $subjects = [];
    
    while ($subject = $subjects_result->fetch_assoc()) {
        $subject_name = getSubjectNameForStudent($conn, $subject['id'], $student_id, $school_attended_id);
        $subjects[] = [
            'id' => $subject['id'],
            'subject_name' => $subject_name,
            'original_name' => $subject['subject_name']
        ];
    }
    
    // Get transfer status and grade info
    // Check if is_transfer column exists
    $has_is_transfer = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'is_transfer'")->num_rows > 0;
    
    if ($has_is_transfer) {
        $school_info = $conn->query("SELECT grade_level, is_transfer, adviser_name FROM schools_attended WHERE id = $school_attended_id")->fetch_assoc();
        
        // Use the is_transfer column value from database (more reliable)
        $is_transfer = !empty($school_info['is_transfer']) ? (bool)$school_info['is_transfer'] : false;
        error_log("Using database is_transfer column for school_attended_id $school_attended_id: is_transfer=" . ($is_transfer ? 'true' : 'false'));
    } else {
        $school_info = $conn->query("SELECT grade_level, adviser_name FROM schools_attended WHERE id = $school_attended_id")->fetch_assoc();
        
        // Fallback: Auto-detect based on adviser (case-insensitive)
        if (!empty($school_info['adviser_name'])) {
            $adviser_check = $conn->query("SELECT id FROM users WHERE LOWER(full_name) = LOWER('" . $conn->real_escape_string($school_info['adviser_name']) . "')")->num_rows;
            $is_transfer = ($adviser_check == 0); // Transfer if adviser not in system
            error_log("Adviser check (case-insensitive) for '{$school_info['adviser_name']}': found $adviser_check, is_transfer=" . ($is_transfer ? 'true' : 'false'));
        } else {
            $is_transfer = true; // No adviser = transfer student
            error_log("No adviser name (no is_transfer column), is_transfer=true");
        }
    }
    
    echo json_encode([
        'success' => true,
        'subjects' => $subjects,
        'is_transfer' => $is_transfer,
        'grade_level' => $school_info['grade_level'],
        'adviser_name' => $school_info['adviser_name'] ?? null // Add for debugging
    ]);
    exit;
}

// AJAX endpoint for getting school subjects configuration
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_school_subjects') {
    header('Content-Type: application/json');
    
    if ($_SESSION['user']['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    // Check if table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'")->num_rows > 0;
    
    if (!$table_exists) {
        echo json_encode(['success' => false, 'message' => 'Please run database migration first']);
        exit;
    }
    
    // Get all subjects
    $all_subjects = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY id");
    
    // Get subjects for each grade level (1-6)
    $subjects_by_grade = [];
    
    for ($grade = 1; $grade <= 6; $grade++) {
        $subjects_by_grade[$grade] = [];
        $all_subjects->data_seek(0); // Reset pointer
        
        while ($subject = $all_subjects->fetch_assoc()) {
            $grade_query = $conn->query("SELECT subject_name FROM subject_grade_groups 
                                         WHERE grade_level = $grade AND subject_id = {$subject['id']}");
            
            $display_name = $subject['subject_name'];
            if ($grade_query && $grade_query->num_rows > 0) {
                $grade_data = $grade_query->fetch_assoc();
                $display_name = $grade_data['subject_name'];
            }
            
            $subjects_by_grade[$grade][] = [
                'subject_id' => $subject['id'],
                'original_name' => $subject['subject_name'],
                'subject_name' => $display_name
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'subjects' => $subjects_by_grade
    ]);
    exit;
}

// AJAX endpoint for saving school subjects configuration
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_school_subjects') {
    header('Content-Type: application/json');
    
    if ($_SESSION['user']['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    // Check if table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'")->num_rows > 0;
    
    if (!$table_exists) {
        echo json_encode(['success' => false, 'message' => 'Please run database migration first']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $subjects = $input['subjects'] ?? [];
    
    if (empty($subjects)) {
        echo json_encode(['success' => false, 'message' => 'No subjects provided']);
        exit;
    }
    
    $updated = 0;
    foreach ($subjects as $subject) {
        $grade_level = intval($subject['grade_level']);
        $subject_id = intval($subject['subject_id']);
        // Allow empty string - user may intentionally clear the display name
        $subject_name = isset($subject['subject_name']) ? trim($subject['subject_name']) : '';

        if ($grade_level < 1 || $grade_level > 6) continue;

        $stmt = $conn->prepare("INSERT INTO subject_grade_groups 
                               (grade_level, subject_id, subject_name, display_order)
                               VALUES (?, ?, ?, 0)
                               ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name)");
        $stmt->bind_param("iis", $grade_level, $subject_id, $subject_name);

        if ($stmt->execute()) {
            $updated++;
        }
    }
    
    // Log the activity
    include "../includes/logger.php";
    logActivity($conn, $_SESSION['user']['id'], 'UPDATE', 'subject_grade_groups', null, 
               "Updated $updated school subject names");
    
    echo json_encode(['success' => true, 'updated' => $updated]);
    exit;
}

// AJAX endpoint for clearing auto-unlock schedule (global)
if (isset($_POST['ajax']) && $_POST['ajax'] === 'clear_auto_unlock') {
    header('Content-Type: application/json');
    
    $user = $_SESSION['user'];
    if ($user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    $quarter = intval($_POST['quarter']);
    
    $result = $conn->query("DELETE FROM quarter_auto_unlocks WHERE school_attended_id IS NULL AND quarter = $quarter");
    
    if ($result) {
        include "../includes/logger.php";
        logActivity($conn, $user['id'], 'DELETE', 'quarter_auto_unlocks', null, 
                   "Auto-unlock schedule cleared globally for Quarter $quarter");
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// AJAX endpoint for getting student school history - MUST BE BEFORE ANY HTML OUTPUT
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_student' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $student_id = intval($_GET['id']);
    $school_history = [];
    
    $user = $_SESSION['user'];
    $current_school_year = date('Y') . '-' . (date('Y') + 1);
    
    // Filter school history based on user role
    if ($user['role'] === 'teacher') {
        // Get teacher's class assignment
        $adviser_query = "SELECT grade_level, section FROM teacher_assignments 
                          WHERE teacher_id = ? AND assignment_type = 'adviser' LIMIT 1";
        $stmt = $conn->prepare($adviser_query);
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $adviser_result = $stmt->get_result();
        $adviser_info = $adviser_result->fetch_assoc();
        
        if ($adviser_info) {
            // Only show school records for teacher's grade level and section
            // If the teacher's assignment has no specific section (empty/null),
            // allow matching all sections for that grade level so advisers who
            // are assigned to the whole grade can still see student records.
            if (!empty($adviser_info['section'])) {
                $stmt = $conn->prepare("SELECT id, 
                                        CONCAT(
                                            COALESCE(grade_level, 'N/A'), 
                                            CASE WHEN section IS NOT NULL AND section != '' THEN CONCAT(' - ', section) ELSE '' END,
                                            ' - ', 
                                            COALESCE(school_year, 'N/A'), 
                                            CASE WHEN adviser_name IS NOT NULL AND adviser_name != '' 
                                                THEN CONCAT(' (Adviser: ', adviser_name, ')') 
                                                ELSE '' 
                                            END
                                        ) AS label
                                        FROM schools_attended 
                                        WHERE student_id = ? AND grade_level = ? AND section = ?
                                        ORDER BY school_year DESC");
                $stmt->bind_param("iis", $student_id, $adviser_info['grade_level'], $adviser_info['section']);
            } else {
                // No section specified: match by grade_level only
                $stmt = $conn->prepare("SELECT id, 
                                        CONCAT(
                                            COALESCE(grade_level, 'N/A'), 
                                            CASE WHEN section IS NOT NULL AND section != '' THEN CONCAT(' - ', section) ELSE '' END,
                                            ' - ', 
                                            COALESCE(school_year, 'N/A'), 
                                            CASE WHEN adviser_name IS NOT NULL AND adviser_name != '' 
                                                THEN CONCAT(' (Adviser: ', adviser_name, ')') 
                                                ELSE '' 
                                            END
                                        ) AS label
                                        FROM schools_attended 
                                        WHERE student_id = ? AND grade_level = ?
                                        ORDER BY school_year DESC");
                $stmt->bind_param("ii", $student_id, $adviser_info['grade_level']);
            }
        } else {
            // Teacher has no assignment - show nothing
            echo json_encode(['school_history' => []]);
            exit;
        }
    } else {
        // Admin can see all school records, sorted by grade level (1-6) then section
        $stmt = $conn->prepare("SELECT id, 
                                CONCAT(
                                    COALESCE(grade_level, 'N/A'), 
                                    CASE WHEN section IS NOT NULL AND section != '' THEN CONCAT(' - ', section) ELSE '' END,
                                    ' - ', 
                                    COALESCE(school_year, 'N/A'), 
                                    CASE WHEN adviser_name IS NOT NULL AND adviser_name != '' 
                                        THEN CONCAT(' (Adviser: ', adviser_name, ')') 
                                        ELSE '' 
                                    END
                                ) AS label
                                FROM schools_attended 
                                WHERE student_id = ? 
                                ORDER BY grade_level ASC, section ASC, school_year DESC");
        $stmt->bind_param("i", $student_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $school_history[] = $row;
    }
    
    echo json_encode(['school_history' => $school_history]);
    exit;
}

// AJAX endpoint for loading saved grades
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_grades') {
    header('Content-Type: application/json');
    
    $student_id = intval($_GET['student_id']);
    $school_attended_id = intval($_GET['school_attended_id']);
    
    // Get grades
    $grades_query = "SELECT subject_id, quarter, grade, final_rating, remarks 
                     FROM grades 
                     WHERE student_id = ? AND school_attended_id = ?";
    $stmt = $conn->prepare($grades_query);
    $stmt->bind_param("ii", $student_id, $school_attended_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $grades = [];
    while ($row = $result->fetch_assoc()) {
        $subject_id = $row['subject_id'];
        if (!isset($grades[$subject_id])) {
            $grades[$subject_id] = [
                'q1' => null,
                'q2' => null,
                'q3' => null,
                'q4' => null,
                'final_rating' => null,
                'remarks' => null
            ];
        }
        
        $quarter = $row['quarter'];
        $grades[$subject_id]['q' . $quarter] = $row['grade'];
        $grades[$subject_id]['final_rating'] = $row['final_rating'];
        $grades[$subject_id]['remarks'] = $row['remarks'];
    }
    
    // Get remedial classes (prefer school_attended_id if available)
    $col_check = $conn->query("SHOW COLUMNS FROM remedial_classes LIKE 'school_attended_id'");
    $has_school_attended = ($col_check && $col_check->num_rows > 0);
    if ($has_school_attended) {
        $remedial_query = "SELECT learning_area, final_rating, remedial_class_mark, 
                                  recomputed_final_grade, remarks, conducted_from, conducted_to 
                           FROM remedial_classes 
                           WHERE student_id = ? AND school_attended_id = ?
                           ORDER BY id LIMIT 2";
        $stmt = $conn->prepare($remedial_query);
        $stmt->bind_param("ii", $student_id, $school_attended_id);
    } else {
        $remedial_query = "SELECT learning_area, final_rating, remedial_class_mark, 
                                  recomputed_final_grade, remarks, conducted_from, conducted_to 
                           FROM remedial_classes 
                           WHERE student_id = ? AND school_year = (
                               SELECT school_year FROM schools_attended WHERE id = ?
                           )
                           ORDER BY id LIMIT 2";
        $stmt = $conn->prepare($remedial_query);
        $stmt->bind_param("ii", $student_id, $school_attended_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $remedial = [];
    while ($row = $result->fetch_assoc()) {
        $remedial[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'grades' => $grades,
        'remedial' => $remedial
    ]);
    exit;
}

// AJAX: Save draft
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_draft') {
    header('Content-Type: application/json');
    
    $user_id = $_SESSION['user']['id'];
    $student_id = intval($_POST['student_id']);
    $school_attended_id = intval($_POST['school_attended_id']);
    $draft_data = $conn->real_escape_string($_POST['draft_data']);
    
    // Insert or update draft
    $query = "INSERT INTO grade_drafts (user_id, student_id, school_attended_id, draft_data)
              VALUES (?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE draft_data = ?, last_updated = CURRENT_TIMESTAMP";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiss", $user_id, $student_id, $school_attended_id, $draft_data, $draft_data);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    exit;
}

// AJAX: Load draft
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_draft') {
    header('Content-Type: application/json');
    
    $user_id = $_SESSION['user']['id'];
    $student_id = intval($_GET['student_id']);
    $school_attended_id = intval($_GET['school_attended_id']);
    
    $query = "SELECT draft_data, last_updated FROM grade_drafts 
              WHERE user_id = ? AND student_id = ? AND school_attended_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $user_id, $student_id, $school_attended_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'draft_data' => $row['draft_data'],
            'last_updated' => $row['last_updated']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No draft found']);
    }
    exit;
}

// AJAX: Delete draft
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_draft') {
    header('Content-Type: application/json');
    
    $user_id = $_SESSION['user']['id'];
    $student_id = intval($_POST['student_id']);
    $school_attended_id = intval($_POST['school_attended_id']);
    
    $query = "DELETE FROM grade_drafts 
              WHERE user_id = ? AND student_id = ? AND school_attended_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $user_id, $student_id, $school_attended_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    exit;
}

// Regular page includes
include "../templates/header.php";
include_once "../includes/logger.php";

$user = $_SESSION['user'];
$current_school_year = date('Y') . '-' . (date('Y') + 1);

// Get students based on user role
if ($user['role'] === 'teacher') {
    // Get teacher's adviser assignment
    $adviser_query = "SELECT grade_level, section FROM teacher_assignments 
                      WHERE teacher_id = ? AND assignment_type = 'adviser' LIMIT 1";
    $stmt = $conn->prepare($adviser_query);
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $adviser_result = $stmt->get_result();
    $adviser_info = $adviser_result->fetch_assoc();
    
    if ($adviser_info) {
        // Only show students from teacher's class
        $has_active = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'active'")->num_rows > 0;
        $students_query = "SELECT DISTINCT s.id, UPPER(CONCAT(s.last_name, ', ', s.first_name)) AS fullname, s.lrn 
                          FROM students s
                          INNER JOIN schools_attended sa ON s.id = sa.student_id
                          WHERE sa.grade_level = ? AND sa.section = ? AND sa.school_year = ?";
        if ($has_active) {
            $students_query .= " AND sa.active = 1";
        }
        $students_query .= " ORDER BY s.last_name";

        $stmt = $conn->prepare($students_query);
        $stmt->bind_param("iss", $adviser_info['grade_level'], $adviser_info['section'], $current_school_year);
        $stmt->execute();
        $students = $stmt->get_result();
    } else {
        // Teacher has no class assigned
        $students = $conn->query("SELECT id, UPPER(CONCAT(last_name, ', ', first_name)) AS fullname, lrn FROM students WHERE 1=0");
    }
} else {
    // Admin can see all students
    $students = $conn->query("SELECT id, UPPER(CONCAT(last_name, ', ', first_name)) AS fullname, lrn FROM students ORDER BY last_name");
}

$subjects = $conn->query("SELECT id, subject_name FROM subjects ORDER BY id");
// Store subjects as array for reuse in JavaScript
if ($subjects && $subjects->num_rows > 0) {
    $subjects_array = array_values($subjects->fetch_all(MYSQLI_ASSOC));
} else {
    $subjects_array = [];
}

// Function to get subject name for a specific student/school_attended record
function getSubjectNameForStudent($conn, $subject_id, $student_id, $school_attended_id) {
    // First, determine if this is a transfer student
    $is_transfer = false;
    $grade_level = null;
    
    // Check if is_transfer column exists
    $has_is_transfer = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'is_transfer'")->num_rows > 0;
    
    if ($has_is_transfer) {
        $school_info = $conn->query("SELECT grade_level, is_transfer, adviser_name FROM schools_attended WHERE id = $school_attended_id");
        if ($school_info && $school_info->num_rows > 0) {
            $school_data = $school_info->fetch_assoc();
            $grade_level = $school_data['grade_level'];
            
            // Use the is_transfer column value from database (more reliable)
            $is_transfer = !empty($school_data['is_transfer']) ? (bool)$school_data['is_transfer'] : false;
        }
    } else {
        // Fallback: Auto-detect based on adviser (case-insensitive)
        $school_info = $conn->query("SELECT grade_level, adviser_name FROM schools_attended WHERE id = $school_attended_id");
        if ($school_info && $school_info->num_rows > 0) {
            $school_data = $school_info->fetch_assoc();
            $grade_level = $school_data['grade_level'];
            
            if (!empty($school_data['adviser_name'])) {
                $adviser_check = $conn->query("SELECT id FROM users WHERE LOWER(full_name) = LOWER('" . $conn->real_escape_string($school_data['adviser_name']) . "')")->num_rows;
                $is_transfer = ($adviser_check == 0); // Transfer if adviser not in system
            } else {
                $is_transfer = true; // No adviser = transfer student
            }
        }
    }
    
    // Check if custom subjects table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'student_custom_subjects'");
    
    if ($table_check && $table_check->num_rows > 0) {
        // Check if student has custom subject name (transfer student specific)
        $custom_query = $conn->query("SELECT custom_subject_name 
                                      FROM student_custom_subjects 
                                      WHERE student_id = $student_id 
                                      AND school_attended_id = $school_attended_id 
                                      AND subject_id = $subject_id");
        
        if ($custom_query && $custom_query->num_rows > 0) {
            $custom = $custom_query->fetch_assoc();
            return $custom['custom_subject_name'];
        }
    }
    
    // IMPORTANT: Only use grade-level config for regular students (non-transfer)
    // Transfer students should NOT be affected by global subject format changes
    error_log("getSubjectNameForStudent: student_id=$student_id, school_attended_id=$school_attended_id, subject_id=$subject_id, is_transfer=" . ($is_transfer ? 'true' : 'false') . ", grade_level=$grade_level");
    
    if (!$is_transfer && $grade_level) {
        // Check if grade groups table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'");
        
        if ($table_check && $table_check->num_rows > 0) {
            // Get subject name from grade level configuration (regular students only)
            $sql = "SELECT subject_name FROM subject_grade_groups WHERE grade_level = $grade_level AND subject_id = $subject_id";
            error_log("getSubjectNameForStudent: Executing query: $sql");
            
            $group_query = $conn->query($sql);
            
            if ($group_query && $group_query->num_rows > 0) {
                $group = $group_query->fetch_assoc();
                error_log("getSubjectNameForStudent: ✓ Found global subject for grade $grade_level, subject $subject_id: " . $group['subject_name']);
                return $group['subject_name'];
            } else {
                error_log("getSubjectNameForStudent: ✗ No global subject found for grade $grade_level, subject $subject_id (query returned " . ($group_query ? $group_query->num_rows : 'NULL') . " rows)");
            }
        } else {
            error_log("getSubjectNameForStudent: ✗ subject_grade_groups table does not exist");
        }
    } else {
        error_log("getSubjectNameForStudent: ✗ Skipping global subjects (is_transfer=" . ($is_transfer ? 'true' : 'false') . ", grade_level=$grade_level)");
    }
    
    // Fall back to default subject name (both transfer and regular students)
    $default_query = $conn->query("SELECT subject_name FROM subjects WHERE id = $subject_id");
    if ($default_query && $default_query->num_rows > 0) {
        $default = $default_query->fetch_assoc();
        return $default['subject_name'];
    }
    
    return 'Unknown Subject';
}
?>

<style>
:root {
    --primary-teal: #4faba9;
    --tab-bg: #f1f3f4;
    --tab-hover: #e8eaed;
    --tab-active: white;
    --tab-text: #5f6368;
    --tab-text-active: #202124;
    --content-bg: white;
    --content-text: #202124;
    --card-bg: #f8f9fa;
    --input-bg: white;
    --input-border: #ced4da;
    --input-text: #212529;
    --input-readonly-bg: #e9ecef;
    --table-border: #dee2e6;
    --tabs-bar-bg: #dee1e6;
}

body.dark-theme {
    --tab-bg: #2d2d2d;
    --tab-hover: #3a3a3a;
    --tab-active: #4a4a4a;
    --tab-text: #888;
    --tab-text-active: #ffffff;
    --content-bg: #1e1e1e;
    --content-text: #e0e0e0;
    --card-bg: #2d2d2d;
    --input-bg: #2d2d2d;
    --input-border: #444;
    --input-text: #ffffff;
    --input-readonly-bg: #1a1a1a;
    --table-border: #444;
    --tabs-bar-bg: #1a1a1a;
}

/* Custom button styles */
.btn-outline-primary[onclick*="toggleSubjectEdit"] {
    background-color: #4faba9 !important;
    border-color: #4faba9 !important;
    color: white !important;
}

.btn-outline-primary[onclick*="toggleSubjectEdit"]:hover {
    background-color: #4faba9 !important;
    border-color: #4faba9 !important;
    color: white !important;
}

#quarterLockBtn {
    background-color: #4faba9 !important;
    border-color: #4faba9 !important;
    color: white !important;
}

#quarterLockBtn:hover {
    background-color: #4faba9 !important;
    border-color: #4faba9 !important;
    color: white !important;
}

.grades-tabs {
    display: flex;
    gap: 2px;
    background: var(--tabs-bar-bg);
    padding: 8px 8px 0;
    border-radius: 8px 8px 0 0;
    align-items: flex-end;
    overflow-x: auto;
    overflow-y: hidden;
}

.grades-tabs::-webkit-scrollbar {
    height: 8px;
}

.grades-tabs::-webkit-scrollbar-track {
    background: transparent;
}

.grades-tabs::-webkit-scrollbar-thumb {
    background: rgba(128,128,128,0.3);
    border-radius: 4px;
}

.grades-tabs::-webkit-scrollbar-thumb:hover {
    background: rgba(128,128,128,0.5);
}

.grade-tab {
    background: var(--tab-bg);
    border: none;
    padding: 10px 20px;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--tab-text);
    transition: background 0.2s;
    position: relative;
    min-width: 200px;
    max-width: 300px;
    flex-shrink: 0;
    white-space: nowrap;
    overflow: hidden;
}

.grade-tab span:nth-child(2) {
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

.grade-tab:hover {
    background: var(--tab-hover);
}

.grade-tab.active {
    background: var(--tab-active);
    color: var(--tab-text-active);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.grade-tab i {
    font-size: 16px;
}

.grade-tab .close-tab {
    margin-left: auto;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    line-height: 1;
    transition: background 0.2s;
}

.grade-tab .close-tab:hover {
    background: rgba(128,128,128,0.3);
}

.add-tab-btn {
    background: transparent;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--tab-text);
    font-size: 20px;
    line-height: 1;
    transition: background 0.2s;
    flex-shrink: 0;
    padding: 0;
    margin-bottom: 4px;
    align-self: center;
}

.add-tab-btn i {
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 0;
}

.add-tab-btn:hover {
    background: rgba(128,128,128,0.2);
}

#tab-container > .tab-content {
    display: none;
    background: var(--content-bg);
    padding: 20px;
    border: 1px solid var(--table-border);
    border-top: none;
    border-radius: 0 0 8px 8px;
    color: var(--content-text);
}

#tab-container > .tab-content.active {
    display: block;
}

.selection-card {
    background: var(--card-bg);
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    color: var(--content-text);
}

.grade-input,
.form-control,
.form-select {
    background-color: var(--input-bg) !important;
    border-color: var(--input-border) !important;
    color: var(--input-text) !important;
    text-align: center;
    height: 38px;
    font-size: 1rem;
    font-weight: 400;
}

/* Remove spinner arrows from number inputs */
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

input[type="number"] {
    -moz-appearance: textfield;
    appearance: textfield;
}

.grade-input::placeholder,
.form-control::placeholder {
    color: #888 !important;
}

input[type="number"].grade-input,
input[type="number"].form-control,
input[type="text"].form-control {
    -webkit-text-fill-color: var(--input-text) !important;
    opacity: 1 !important;
}

.grade-input:focus,
.form-control:focus,
.form-select:focus {
    background-color: var(--input-bg) !important;
    border-color: var(--primary-teal) !important;
    color: var(--input-text) !important;
    -webkit-text-fill-color: var(--input-text) !important;
}

.grade-input[readonly],
.form-control[readonly] {
    background-color: var(--input-readonly-bg) !important;
    color: #6c757d !important;
    -webkit-text-fill-color: #6c757d !important;
}

.readonly-light {
    background-color: #FFFFFF !important;
}

body.dark-theme .readonly-light {
    background-color: #2d2d2d !important;
}

select.form-control,
select.form-select {
    color: var(--input-text) !important;
    background-color: var(--input-bg) !important;
}

select.form-control option,
select.form-select option {
    background-color: var(--input-bg);
    color: var(--input-text);
}

.table {
    color: var(--content-text);
    table-layout: fixed;
    width: 100%;
}

/* Ensure table-bordered draws complete borders consistently */
.table.table-bordered {
    border-collapse: collapse;
    border-spacing: 0;
    border: 1px solid var(--table-border) !important;
}
.table.table-bordered th,
.table.table-bordered td {
    border: 1px solid var(--table-border) !important;
}
.table.table-bordered thead th,
.table.table-bordered tbody td {
    border-right: 1px solid var(--table-border) !important;
}

.table thead th:first-child {
    width: 200px;
}

.table thead th:nth-child(2),
.table thead th:nth-child(3),
.table thead th:nth-child(4),
.table thead th:nth-child(5) {
    width: 100px;
}

.table thead th:nth-child(6) {
    width: 120px;
}

.table thead th:nth-child(7) {
    width: 100px;
}

.table-bordered {
    border-color: var(--table-border);
}

.table > :not(caption) > * > * {
    border-color: var(--table-border);
    background-color: transparent;
}

.table thead {
    background-color: var(--card-bg) !important;
    color: var(--content-text);
}

.table tbody td {
    color: var(--content-text);
}

/* Align remedial and table inputs to the same appearance as grade inputs */
.table tbody td .grade-input,
.table tbody td input.grade-input,
.table tbody td .form-control,
.table tbody td .form-select {
    height: 38px;
    padding: 0.375rem 0.75rem;
    border-radius: 0.375rem;
    background: var(--input-bg);
    border: 1px solid var(--input-border);
    color: var(--input-text);
    box-sizing: border-box;
    width: 100%;
    display: block;
    font-size: 1rem;
    font-weight: 400;
}
.table tbody td input.grade-input,
.table tbody td .grade-input {
    text-align: center;
}

.mapeh-cell-inner {
    display: block;
    width: 100%;
    height: 38px;
    padding: 0.375rem 0.75rem;
    background-color: #FFFFFF;
    border: 1px solid var(--input-border);
    border-radius: 0.375rem;
    color: var(--input-text);
    text-align: center;
    font-size: 1rem;
    font-weight: 400;
    line-height: 1.5;
    box-sizing: border-box;
}

body.dark-theme .mapeh-cell-inner {
    background-color: #2D2F33;
    border-color: var(--input-border);
    color: var(--input-text);
}

/* Auto-lock animation */
.auto-lock-animate .form-check-input {
    transition: all 0.3s ease;
}
</style>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-clipboard-data"></i> Grades Entry (SF10)</h2>
        <p class="subtitle">Manage student grades and performance records</p>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" id="subjectManagementBtn" onclick="openSchoolSubjectsModal()">
            <i class="bi bi-book" id="subjectBtnIcon"></i> <span id="subjectBtnText">Manage School Subjects</span>
        </button>
        <button class="btn btn-warning" id="quarterLockBtn" onclick="openQuarterLockModalFromHeader()">
            <i class="bi bi-lock-fill"></i> Manage Quarter Locks
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= $_SESSION['error_message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class='alert alert-success alert-dismissible fade show'>
        <i class="bi bi-check-circle-fill"></i> Grades saved successfully!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php if (isset($_SESSION['grades_saved_for_student'])): ?>
    <script>
        // Broadcast to other tabs that grades were saved
        localStorage.setItem('gradesSaved', JSON.stringify({
            studentId: <?= $_SESSION['grades_saved_for_student'] ?>,
            schoolAttendedId: <?= $_SESSION['grades_saved_for_school'] ?? 'null' ?>,
            timestamp: new Date().getTime()
        }));
        // Clear immediately to allow future broadcasts
        localStorage.removeItem('gradesSaved');
        
        // Mark grades as saved (turn green)
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = sessionStorage.getItem(storagePrefix + 'activeTabBeforeSubmit');
            if (activeTab) {
                setTimeout(() => {
                    markGradesAsSaved(activeTab);
                }, 100);
            }
        });
    </script>
    <?php unset($_SESSION['grades_saved_for_student']); ?>
    <?php unset($_SESSION['grades_saved_for_school']); ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Chrome-style Tabs -->
<div class="grades-tabs">
    <button class="grade-tab active" id="main-tab-btn">
      <i class="bi bi-house-door"></i>
      <span>Select Student</span>
      <span class="close-tab" id="main-tab-close">×</span>
    </button>
    <button class="add-tab-btn" title="Add Student Tab">
      <i class="bi bi-plus"></i>
    </button>
  </div>

  <!-- Tab Contents -->
  <div id="tab-container">
    <!-- Main Tab: Student Selection -->
    <div class="tab-content active" id="main-tab">
      <div class="selection-card">
        <h5><i class="bi bi-person-circle"></i> Select Student</h5>
        <div class="mb-3">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="studentSearchBox" class="form-control" placeholder="Search by name or LRN...">
          </div>
        </div>
        <select id="studentSelector" class="form-select form-select-lg" size="15" style="height: auto; min-height: 400px;">
          <option value="">-- Choose a student --</option>
          <?php 
          $students->data_seek(0);
          while($s = $students->fetch_assoc()): 
          ?>
            <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['fullname']) ?>" data-lrn="<?= htmlspecialchars($s['lrn']) ?>">
              <?= htmlspecialchars($s['fullname']) ?> (LRN: <?= htmlspecialchars($s['lrn']) ?>)
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="text-center py-5 text-muted">
        <i class="bi bi-clipboard-data" style="font-size: 48px;"></i>
        <h4 class="mt-3">Multi-Student Grade Entry</h4>
        <p>Select a student above to start entering grades</p>
        <p class="small">You can work on multiple students at the same time using tabs!</p>
      </div>
    </div>
  </div>
</div>

<script>
const userId = <?= isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : 0 ?>;
const userRole = <?= json_encode($_SESSION['user']['role'] ?? 'teacher') ?>;
const storagePrefix = 'user' + userId + '_';
console.log('User ID:', userId, 'User Role:', userRole, 'Storage Prefix:', storagePrefix);

let tabCounter = 0;
let activeTabs = [];

// Save tabs to sessionStorage
function saveTabs() {
    sessionStorage.setItem(storagePrefix + 'gradeTabs', JSON.stringify(activeTabs));
    sessionStorage.setItem(storagePrefix + 'tabCounter', tabCounter);
}

// Save form data for a specific tab
function saveTabFormData(tabId) {
    // Check if auto-save is disabled for this tab (during cross-tab reload)
    const safeName = tabId.replace(/-/g, '_');
    if (window['skipAutoSave_' + safeName]) {
        console.log(`⏸️ Auto-save skipped for ${tabId} (cross-tab reload in progress)`);
        return;
    }
    
    const tabContent = document.getElementById(tabId);
    if (!tabContent) return;
    
    const formData = [];
    
    // Save all input, select, and textarea values with their index
    tabContent.querySelectorAll('input, select, textarea').forEach((field, index) => {
        if (field.name || field.id) {
            // Skip subject_name fields - they come from global subject config, not user input
            if (field.name && field.name.includes('[subject_name]')) return;
            
            const fieldData = {
                index: index,
                name: field.name,
                id: field.id,
                type: field.type
            };
            
            if (field.type === 'checkbox') {
                fieldData.checked = field.checked;
            } else if (field.type === 'radio') {
                fieldData.checked = field.checked;
                fieldData.value = field.value;
            } else {
                fieldData.value = field.value;
            }
            
            formData.push(fieldData);
        }
    });
    
    sessionStorage.setItem(storagePrefix + 'tabFormData_' + tabId, JSON.stringify(formData));
    console.log('Saved form data for', tabId, formData);
}

// Restore form data for a specific tab
function restoreTabFormData(tabId) {
    const savedData = sessionStorage.getItem(storagePrefix + 'tabFormData_' + tabId);
    if (!savedData) {
        console.log('No saved data for', tabId);
        return;
    }
    
    const formData = JSON.parse(savedData);
    const tabContent = document.getElementById(tabId);
    if (!tabContent) return;
    
    const allFields = Array.from(tabContent.querySelectorAll('input, select, textarea'));
    
    // Restore all field values using index
    formData.forEach(savedField => {
        const field = allFields[savedField.index];
        
        if (field && (field.name === savedField.name || field.id === savedField.id)) {
            // Skip subject_name fields - they come from global subject config via loadSubjectNames()
            if (field.name && field.name.includes('[subject_name]')) return;
            
            if (field.type === 'checkbox') {
                field.checked = savedField.checked;
            } else if (field.type === 'radio') {
                field.checked = savedField.checked;
            } else if (savedField.value !== undefined && savedField.value !== '') {
                field.value = savedField.value;
                // Trigger input event to recalculate grades
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    });
    
    console.log('Restored form data for', tabId);
}

// Load tabs from sessionStorage
function loadTabs() {
    const savedTabs = sessionStorage.getItem(storagePrefix + 'gradeTabs');
    const savedCounter = sessionStorage.getItem(storagePrefix + 'tabCounter');
    const mainTabStudent = sessionStorage.getItem(storagePrefix + 'mainTabStudent');
    const savedActiveTab = sessionStorage.getItem(storagePrefix + 'activeTab');
    
    console.log('Loading tabs - savedTabs:', savedTabs);
    console.log('Loading tabs - savedCounter:', savedCounter);
    console.log('Loading tabs - mainTabStudent:', mainTabStudent);
    console.log('Loading tabs - savedActiveTab:', savedActiveTab);
    
    // Restore main tab if it had a student loaded
    if (mainTabStudent) {
        const mainTab = JSON.parse(mainTabStudent);
        loadStudentToMainTab(mainTab.studentId, mainTab.studentName);
    }
    
    if (savedTabs) {
        activeTabs = JSON.parse(savedTabs);
        tabCounter = parseInt(savedCounter) || 0;
        
        console.log('Restoring tabs from sessionStorage:', activeTabs);
        
        // Restore each tab
        activeTabs.forEach(tab => {
            restoreTab(tab);
        });
    }
    
    // Restore active tab after a short delay to ensure tabs are created
    setTimeout(() => {
        if (savedActiveTab) {
            const tabElement = document.getElementById(savedActiveTab);
            const tabButton = document.getElementById(savedActiveTab + '-btn');
            if (tabElement && tabButton) {
                switchTab(savedActiveTab);
                console.log('Restored active tab:', savedActiveTab);
            }
        }
    }, 100);
}

// Restore a saved tab
function restoreTab(tabData) {
    const tabId = tabData.id;
    
    // Create tab button
    const tabsContainer = document.querySelector('.grades-tabs');
    const addButton = document.querySelector('.add-tab-btn');
    
    const tabButton = document.createElement('button');
    tabButton.className = 'grade-tab';
    tabButton.id = tabId + '-btn';
    tabButton.innerHTML = `<i class="bi bi-person-fill"></i><span>${tabData.studentName}</span><span class="close-tab">×</span>`;
    
    tabsContainer.insertBefore(tabButton, addButton);
    
    // Create tab content
    const tabContainer = document.getElementById('tab-container');
    const tabContent = document.createElement('div');
    tabContent.className = 'tab-content';
    tabContent.id = tabId;
    tabContainer.appendChild(tabContent);
    
    // Add event listeners
    tabButton.addEventListener('click', () => switchTab(tabId));
    tabButton.querySelector('.close-tab').addEventListener('click', (e) => {
        e.stopPropagation();
        // Check if tab has grade form loaded
        const tabContent = document.getElementById(tabId);
        const hasForm = tabContent && tabContent.querySelector('input[name="student_id"]');
        if (hasForm) {
            // Show modal if form is loaded
            closeTab(tabId);
        } else {
            // No form, close directly without modal
            tabToClose = tabId;
            confirmCloseTabAction();
        }
    });
    
    // Load the student data or show selection if no student
    if (tabData.studentId) {
        loadGradeForm(tabId, tabData.studentId, tabData.studentName);
    } else {
        // Show student selector for empty tab
        tabButton.innerHTML = '<i class="bi bi-person-circle"></i><span>Select Student</span><span class="close-tab">×</span>';
        tabContent.innerHTML = `
            <div class="selection-card">
                <h5><i class="bi bi-person-circle"></i> Select Student for This Tab</h5>
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control tab-search-box" placeholder="Search by name or LRN..." data-tab-id="${tabId}">
                    </div>
                </div>
                <select class="form-select form-select-lg tab-student-selector" data-tab-id="${tabId}" size="15" style="height: auto; min-height: 400px;">
                    <option value="">-- Choose a student --</option>
                    <?php 
                    $students->data_seek(0);
                    while($s = $students->fetch_assoc()): 
                    ?>
                    <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['fullname']) ?>" data-lrn="<?= htmlspecialchars($s['lrn']) ?>">
                        <?= htmlspecialchars($s['fullname']) ?> (LRN: <?= htmlspecialchars($s['lrn']) ?>)
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-clipboard-data" style="font-size: 48px;"></i>
                <h4 class="mt-3">Select a Student</h4>
                <p>Choose a student from the dropdown above to start entering grades</p>
            </div>
        `;
        
        // Add search and selection functionality
        const tabSearchBox = tabContent.querySelector('.tab-search-box');
        const tabSelector = tabContent.querySelector('.tab-student-selector');
        const tabOptions = Array.from(tabSelector.options);
        
        tabSearchBox.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            tabOptions.forEach(option => {
                if (option.value === '') {
                    option.style.display = '';
                    return;
                }
                
                const name = option.getAttribute('data-name').toLowerCase();
                const lrn = option.getAttribute('data-lrn').toLowerCase();
                
                if (name.includes(searchTerm) || lrn.includes(searchTerm)) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
        });
        
        tabSelector.addEventListener('change', function() {
            if (this.value) {
                const studentName = this.options[this.selectedIndex].getAttribute('data-name');
                loadStudentToTab(tabId, this.value, studentName);
            }
        });
        
        // Re-attach close button event listener for empty tab
        tabButton.querySelector('.close-tab').onclick = function(e) {
            e.stopPropagation();
            // No form, close directly without modal
            tabToClose = tabId;
            confirmCloseTabAction();
        };
    }
}

// Add event listeners on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded - initializing grade entry system');
    
    // Initialize close tab modal
    const closeTabModal = new bootstrap.Modal(document.getElementById('closeTabModal'));
    window.closeTabModal = closeTabModal; // Make it globally accessible
    console.log('Close tab modal initialized');
    
    // Listen for grade saves in other tabs
    window.addEventListener('storage', function(e) {
        console.log('📡 Storage event received:', e.key);
        
        if (e.key === 'gradesSaved' && e.newValue) {
            const data = JSON.parse(e.newValue);
            const savedStudentId = data.studentId;
            const savedSchoolId = data.schoolAttendedId;
            
            console.log('💾 Grades saved broadcast received:', {
                studentId: savedStudentId,
                schoolAttendedId: savedSchoolId,
                timestamp: data.timestamp
            });
            
            // Refresh all tabs with matching student AND school_attended_id
            // Check main tab
            const mainTabStudent = sessionStorage.getItem(storagePrefix + 'mainTabStudent');
            if (mainTabStudent) {
                const mainData = JSON.parse(mainTabStudent);
                const mainSchoolSelect = document.getElementById('school-select-main-tab');
                const mainSchoolId = mainSchoolSelect ? mainSchoolSelect.value : null;
                
                console.log('🔍 Checking main-tab:', {
                    tabStudentId: mainData.studentId,
                    tabSchoolId: mainSchoolId,
                    savedStudentId: savedStudentId,
                    savedSchoolId: savedSchoolId,
                    studentMatch: mainData.studentId == savedStudentId,
                    schoolMatch: mainSchoolId == savedSchoolId
                });
                
                if (mainData.studentId == savedStudentId && 
                    mainSchoolSelect && 
                    mainSchoolId == savedSchoolId) {
                    
                    console.log('🔄 Auto-refreshing main tab - exact match (student + school)');
                    
                    // COMPLETELY REMOVE grade data from sessionStorage
                    sessionStorage.removeItem(storagePrefix + 'tabFormData_main-tab');
                    
                    // Set flag to prevent auto-save during reload
                    window['skipAutoSave_main_tab'] = true;
                    
                    // Pass true to skip sessionStorage restore - show pure database values
                    loadSavedGrades('main-tab', savedStudentId, true).then(() => {
                        // Re-enable auto-save after reload
                        setTimeout(() => {
                            delete window['skipAutoSave_main_tab'];
                        }, 500);
                    });
                }
            }
            
            // Check all other tabs
            console.log('🔍 Checking', activeTabs.length, 'other tabs');
            activeTabs.forEach(tab => {
                const tabSchoolSelect = document.getElementById('school-select-' + tab.id);
                const tabSchoolId = tabSchoolSelect ? tabSchoolSelect.value : null;
                
                console.log(`🔍 Checking ${tab.id}:`, {
                    tabStudentId: tab.studentId,
                    tabSchoolId: tabSchoolId,
                    savedStudentId: savedStudentId,
                    savedSchoolId: savedSchoolId,
                    studentMatch: tab.studentId == savedStudentId,
                    schoolMatch: tabSchoolId == savedSchoolId
                });
                
                if (tab.studentId == savedStudentId && 
                    tabSchoolSelect && 
                    tabSchoolId == savedSchoolId) {
                    
                    console.log('🔄 Auto-refreshing', tab.id, '- exact match (student + school)');
                    
                    // COMPLETELY REMOVE grade data from sessionStorage
                    sessionStorage.removeItem(storagePrefix + 'tabFormData_' + tab.id);
                    
                    // Set flag to prevent auto-save during reload
                    const safeName = tab.id.replace(/-/g, '_');
                    window['skipAutoSave_' + safeName] = true;
                    
                    // Pass true to skip sessionStorage restore - show pure database values
                    loadSavedGrades(tab.id, savedStudentId, true).then(() => {
                        // Re-enable auto-save after reload
                        setTimeout(() => {
                            delete window['skipAutoSave_' + safeName];
                        }, 500);
                    });
                }
            });
        }
        
        // Listen for unsaved grade changes in other tabs
        if (e.key === 'gradeInputChanged' && e.newValue) {
            const data = JSON.parse(e.newValue);
            const changedStudentId = data.studentId;
            
            // Check main tab
            const mainTabStudent = sessionStorage.getItem(storagePrefix + 'mainTabStudent');
            if (mainTabStudent) {
                const mainData = JSON.parse(mainTabStudent);
                if (mainData.studentId == changedStudentId) {
                    updateGradeIndicator('main-tab', data.fieldName, data.value, data.isUnsaved);
                }
            }
            
            // Check all other tabs
            activeTabs.forEach(tab => {
                if (tab.studentId == changedStudentId) {
                    updateGradeIndicator(tab.id, data.fieldName, data.value, data.isUnsaved);
                }
            });
        }
        
        // Listen for global subject name updates
        if (e.key === 'subjectNamesUpdated' && e.newValue) {
            const data = JSON.parse(e.newValue);
            const updatedGradeLevel = data.gradeLevel;
            
            console.log('Global subject names updated for grade level:', updatedGradeLevel);
            
            // Reload subjects for all tabs with matching grade level
            if (window.tabSubjectData) {
                Object.keys(window.tabSubjectData).forEach(async (tabId) => {
                    const tabData = window.tabSubjectData[tabId];
                    if (tabData.grade_level == updatedGradeLevel && !tabData.is_transfer) {
                        console.log('Reloading subjects for tab:', tabId, 'grade level:', updatedGradeLevel);
                        await loadSubjectNames(tabId, tabData.studentId);
                        console.log('Subject names reloaded from broadcast for tab:', tabId);
                    }
                });
            }
        }
    });
    
    // Check if we need to reload grades after validation error
    <?php if (isset($_SESSION['reload_grades'])): ?>
        const studentId = <?= $_SESSION['reload_student_id'] ?>;
        const schoolId = <?= $_SESSION['reload_school_id'] ?>;
        
        // Don't clear the entire form data - only clear the grade inputs in sessionStorage
        const savedFormData = sessionStorage.getItem(storagePrefix + 'tabFormData_main-tab');
        if (savedFormData) {
            const formData = JSON.parse(savedFormData);
            // Remove only grade input values, keep school selection
            const cleanedData = formData.filter(field => 
                field.name !== 'grades' && !field.name?.startsWith('grades[')
            );
            sessionStorage.setItem(storagePrefix + 'tabFormData_main-tab', JSON.stringify(cleanedData));
        }
        
        // Force reload grades from database after form is rendered (restore unsaved work)
        setTimeout(() => {
            const schoolSelect = document.querySelector('#main-tab select[name="school_attended_id"]');
            if (schoolSelect && schoolSelect.value == schoolId) {
                loadSavedGrades('main-tab', studentId); // Use default: restore sessionStorage
            }
        }, 500);
        
        console.log('Reloading grades from database due to validation error');
        <?php 
            unset($_SESSION['reload_grades']);
            unset($_SESSION['reload_student_id']);
            unset($_SESSION['reload_school_id']);
        ?>
    <?php endif; ?>
    
    // Load saved tabs
    loadTabs();
    
    // Check if student_id is in URL (e.g., coming from my_class.php)
    const urlParams = new URLSearchParams(window.location.search);
    const urlStudentId = urlParams.get('student_id');
    const urlSchoolAttendedId = urlParams.get('school_attended_id');
    const urlSchoolYear = urlParams.get('school_year');
    const openNewTab = urlParams.get('open_new_tab');
    
    if (urlStudentId) {
        console.log('📋 Student ID found in URL:', urlStudentId);
        console.log('📋 School Attended ID:', urlSchoolAttendedId);
        console.log('📋 School Year:', urlSchoolYear);
        console.log('📋 Open in new tab:', openNewTab);
        
        // Wait for tabs to be fully loaded before proceeding
        setTimeout(() => {
            // Find student name from selector
            const studentSelector = document.getElementById('studentSelector');
            if (studentSelector) {
                const option = Array.from(studentSelector.options).find(opt => opt.value == urlStudentId);
                if (option) {
                    const studentName = option.getAttribute('data-name');
                    console.log('✓ Found student:', studentName);
                    
                    if (openNewTab === '1') {
                        // Open in a new tab
                        console.log('🆕 Opening student in new tab...');
                        
                        // Create new tab and get its ID
                        const newTabId = addNewTab();
                        
                        // Wait a bit for tab to be created, then load student
                        setTimeout(() => {
                            // Store the school_attended_id temporarily for this tab
                            if (urlSchoolAttendedId) {
                                window['autoSelectSchool_' + newTabId] = {
                                    schoolAttendedId: urlSchoolAttendedId,
                                    schoolYear: urlSchoolYear
                                };
                            }
                            
                            loadStudentToTab(newTabId, urlStudentId, studentName);
                            
                            // Clean URL (remove parameters after loading)
                            window.history.replaceState({}, '', 'grades.php');
                        }, 300);
                    } else {
                        // Load to main tab (original behavior)
                        const mainTabStudent = sessionStorage.getItem(storagePrefix + 'mainTabStudent');
                        
                        // Only auto-load if main tab is empty OR if it's a different student
                        if (!mainTabStudent || JSON.parse(mainTabStudent).studentId != urlStudentId) {
                            console.log('🔄 Auto-loading student to main tab from URL...');
                            
                            // Store the school_attended_id temporarily for main tab
                            if (urlSchoolAttendedId) {
                                window['autoSelectSchool_main-tab'] = {
                                    schoolAttendedId: urlSchoolAttendedId,
                                    schoolYear: urlSchoolYear
                                };
                            }
                            
                            loadStudentToMainTab(urlStudentId, studentName);
                            
                            // Clean URL (remove parameters after loading)
                            window.history.replaceState({}, '', 'grades.php');
                        } else {
                            console.log('ℹ Main tab already has this student loaded');
                        }
                    }
                } else {
                    console.warn('⚠ Student ID not found in selector:', urlStudentId);
                }
            }
        }, 300);
    }
    
    // Restore active tab after form submission
    const activeTabBeforeSubmit = sessionStorage.getItem(storagePrefix + 'activeTabBeforeSubmit');
    if (activeTabBeforeSubmit) {
        console.log('Restoring active tab after submit:', activeTabBeforeSubmit);
        // Wait a bit for tabs to be fully loaded
        setTimeout(() => {
            const tabElement = document.getElementById(activeTabBeforeSubmit);
            const tabButton = document.getElementById(activeTabBeforeSubmit + '-btn');
            if (tabElement && tabButton) {
                switchTab(activeTabBeforeSubmit);
            }
            // Clear the saved tab after restoring
            sessionStorage.removeItem(storagePrefix + 'activeTabBeforeSubmit');
        }, 100);
    }
    
    // Save form data before navigating away
    window.addEventListener('beforeunload', function() {
        // Save main tab form data if it has a student loaded
        if (sessionStorage.getItem(storagePrefix + 'mainTabStudent')) {
            saveTabFormData('main-tab');
        }
        
        // Save all other tabs form data
        activeTabs.forEach(tab => {
            saveTabFormData(tab.id);
        });
    });
    
    // Student search functionality
    const searchBox = document.getElementById('studentSearchBox');
    const selector = document.getElementById('studentSelector');
    console.log('Student selector elements:', { searchBox: !!searchBox, selector: !!selector });
    
    if (searchBox && selector) {
        const allOptions = Array.from(selector.options);
        
        searchBox.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            allOptions.forEach(option => {
                if (option.value === '') {
                    option.style.display = '';
                    return;
                }
                
                const name = option.getAttribute('data-name').toLowerCase();
                const lrn = option.getAttribute('data-lrn').toLowerCase();
                
                if (name.includes(searchTerm) || lrn.includes(searchTerm)) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
        });
        
        selector.addEventListener('change', function() {
            console.log('Student selector changed, value:', this.value);
            if (this.value) {
                const studentName = this.options[this.selectedIndex].getAttribute('data-name');
                console.log('Loading student:', studentName, 'ID:', this.value);
                loadStudentToMainTab(this.value, studentName);
            }
        });
    } else {
        console.error('Student searchBox or selector not found!');
    }

    const mainTabBtn = document.getElementById('main-tab-btn');
    if (mainTabBtn) {
        mainTabBtn.addEventListener('click', function(e) {
            if (!e.target.classList.contains('close-tab')) {
                switchTab('main-tab');
            }
        });
    }
    
    const mainTabClose = document.getElementById('main-tab-close');
    if (mainTabClose) {
        mainTabClose.onclick = function(e) {
            e.stopPropagation();
            // Check if studentSelector exists (means no student selected yet)
            const studentSelector = document.getElementById('studentSelector');
            if (studentSelector) {
                // Student selector visible means no student loaded, reset directly
                confirmResetMainTab();
            } else {
                // Grade form loaded, show modal
                tabToClose = 'main-tab';
                window.closeTabModal.show();
            }
        };
    }

    document.querySelector('.add-tab-btn').addEventListener('click', function() {
        addNewTab();
    });
    
    // Handle confirm close tab button
    document.getElementById('confirmCloseTab').addEventListener('click', function() {
        confirmCloseTabAction();
        window.closeTabModal.hide();
    });
    
    // Start real-time auto-lock checker
    startAutoLockChecker();
    
    // Auto-hide alert messages after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000); // 5 seconds
    });
});

// Real-time auto-lock checker
let processedAutoLocks = new Set();
let processedAutoUnlocks = new Set();

function startAutoLockChecker() {
    // Check every second for real-time response
    setInterval(async function() {
        try {
            // Get all scheduled auto-lock times
            const response = await fetch('grades.php?ajax=get_auto_lock_times');
            const data = await response.json();
            
            if (data.success && (data.auto_locks || data.auto_unlocks)) {
                const now = new Date();
                
                // Check auto-locks
                if (data.auto_locks) {
                    for (let quarter in data.auto_locks) {
                        const scheduledTime = new Date(data.auto_locks[quarter]);
                        const lockKey = `lock-${quarter}-${scheduledTime.getTime()}`;
                        
                        if (now >= scheduledTime && !processedAutoLocks.has(lockKey)) {
                            // Time has arrived and not yet processed, trigger the lock
                            processedAutoLocks.add(lockKey);
                            await applyAutoLock(parseInt(quarter.replace('q', '')));
                        }
                    }
                }
                
                // Check auto-unlocks
                if (data.auto_unlocks) {
                    for (let quarter in data.auto_unlocks) {
                        const scheduledTime = new Date(data.auto_unlocks[quarter]);
                        const unlockKey = `unlock-${quarter}-${scheduledTime.getTime()}`;
                        
                        if (now >= scheduledTime && !processedAutoUnlocks.has(unlockKey)) {
                            // Time has arrived and not yet processed, trigger the unlock
                            processedAutoUnlocks.add(unlockKey);
                            await applyAutoUnlock(parseInt(quarter.replace('q', '')));
                        }
                    }
                }
            }
        } catch (error) {
            console.error('Error checking auto-lock times:', error);
        }
    }, 1000); // Check every 1 second for real-time response
}

async function applyAutoLock(quarter) {
    try {
        // Check if already locked to avoid unnecessary requests
        const checkbox = document.getElementById(`lock-q${quarter}`);
        if (checkbox && checkbox.checked) {
            console.log(`Quarter ${quarter} is already locked, skipping`);
            return;
        }
        
        const response = await fetch('grades.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ajax=toggle_quarter_lock&quarter=${quarter}&locked=1`
        });
        const data = await response.json();
        
        if (data.success) {
            console.log(`Quarter ${quarter} auto-locked successfully`);
            
            // Update the toggle button UI
            const label = document.getElementById(`lock-q${quarter}-label`);
            
            if (checkbox && label) {
                // Update checkbox state
                checkbox.checked = true;
                
                // Update label
                label.textContent = 'Locked';
                label.className = 'text-danger fw-bold';
            }
            
            // Reload quarter locks for all active tabs that have a student loaded
            reloadAllTabLocks();
            
            // Show non-blocking notification (console only)
            console.log(`✓ Quarter ${quarter} has been automatically locked`);
        }
    } catch (error) {
        console.error(`Error auto-locking quarter ${quarter}:`, error);
    }
}

async function applyAutoUnlock(quarter) {
    try {
        // Check if already unlocked to avoid unnecessary requests
        const checkbox = document.getElementById(`lock-q${quarter}`);
        if (checkbox && !checkbox.checked) {
            console.log(`Quarter ${quarter} is already unlocked, skipping`);
            return;
        }
        
        const response = await fetch('grades.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ajax=toggle_quarter_lock&quarter=${quarter}&locked=0`
        });
        const data = await response.json();
        
        if (data.success) {
            console.log(`Quarter ${quarter} auto-unlocked successfully`);
            
            // Update the toggle button UI
            const label = document.getElementById(`lock-q${quarter}-label`);
            
            if (checkbox && label) {
                // Update checkbox state
                checkbox.checked = false;
                
                // Update label
                label.textContent = 'Unlocked';
                label.className = 'text-success';
            }
            
            // Reload quarter locks for all active tabs that have a student loaded
            reloadAllTabLocks();
            
            // Show non-blocking notification (console only)
            console.log(`✓ Quarter ${quarter} has been automatically unlocked`);
        }
    } catch (error) {
        console.error(`Error auto-unlocking quarter ${quarter}:`, error);
    }
}

function reloadAllTabLocks() {
    // Reload main tab if it has a student
    const mainTabSchoolSelect = document.querySelector('#main-tab select[name="school_attended_id"]');
    if (mainTabSchoolSelect && mainTabSchoolSelect.value) {
        loadQuarterLocks('main-tab');
    }
    
    // Reload all other active tabs
    if (window.activeTabs) {
        window.activeTabs.forEach(tab => {
            const tabSchoolSelect = document.querySelector(`#${tab.id} select[name="school_attended_id"]`);
            if (tabSchoolSelect && tabSchoolSelect.value) {
                loadQuarterLocks(tab.id);
            }
        });
    }
}

function switchTab(tabId) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.grade-tab').forEach(btn => btn.classList.remove('active'));
    
    // Show selected tab
    document.getElementById(tabId).classList.add('active');
    document.getElementById(tabId + '-btn').classList.add('active');
    
    // Save active tab to sessionStorage
    sessionStorage.setItem(storagePrefix + 'activeTab', tabId);
    
    // Update quarter lock button context for admin
    const lockBtn = document.getElementById('quarterLockBtn');
    if (lockBtn) {
        const currentTabContent = document.getElementById(tabId);
        const hasStudentForm = currentTabContent ? currentTabContent.querySelector('input[name="student_id"]') : null;
        
        if (hasStudentForm) {
            currentLockTabId = tabId;
            
            // Update current school ID if available
            const schoolSelect = currentTabContent.querySelector('[id^="school-select-"]');
            currentLockSchoolId = schoolSelect && schoolSelect.value ? schoolSelect.value : null;
        } else {
            currentLockTabId = null;
            currentLockSchoolId = null;
        }
    }
}

function addNewTab() {
    tabCounter++;
    const tabId = 'tab-' + tabCounter;
    
    // Create tab button
    const tabsContainer = document.querySelector('.grades-tabs');
    const addButton = document.querySelector('.add-tab-btn');
    
    const tabButton = document.createElement('button');
    tabButton.className = 'grade-tab';
    tabButton.id = tabId + '-btn';
    tabButton.innerHTML = '<i class="bi bi-person-circle"></i><span>Select Student</span><span class="close-tab">×</span>';
    
    tabsContainer.insertBefore(tabButton, addButton);
    
    // Create tab content
    const tabContainer = document.getElementById('tab-container');
    const tabContent = document.createElement('div');
    tabContent.className = 'tab-content';
    tabContent.id = tabId;
    tabContent.innerHTML = `
        <div class="selection-card">
            <h5><i class="bi bi-person-circle"></i> Select Student for This Tab</h5>
            <div class="mb-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control tab-search-box" placeholder="Search by name or LRN..." data-tab-id="${tabId}">
                </div>
            </div>
            <select class="form-select form-select-lg tab-student-selector" data-tab-id="${tabId}" size="15" style="height: auto; min-height: 400px;">
                <option value="">-- Choose a student --</option>
                <?php 
                $students->data_seek(0);
                while($s = $students->fetch_assoc()): 
                ?>
                <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['fullname']) ?>" data-lrn="<?= htmlspecialchars($s['lrn']) ?>">
                    <?= htmlspecialchars($s['fullname']) ?> (LRN: <?= htmlspecialchars($s['lrn']) ?>)
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clipboard-data" style="font-size: 48px;"></i>
            <h4 class="mt-3">Select a Student</h4>
            <p>Choose a student from the dropdown above to start entering grades</p>
        </div>
    `;
    
    tabContainer.appendChild(tabContent);
    
    // Add event listeners
    tabButton.addEventListener('click', () => switchTab(tabId));
    tabButton.querySelector('.close-tab').addEventListener('click', (e) => {
        e.stopPropagation();
        // Check if tab has grade form loaded
        const tabContent = document.getElementById(tabId);
        const hasForm = tabContent && tabContent.querySelector('input[name="student_id"]');
        if (hasForm) {
            // Show modal if form is loaded
            closeTab(tabId);
        } else {
            // No form, close directly without modal
            tabToClose = tabId;
            confirmCloseTabAction();
        }
    });
    
    // Add search functionality for this tab
    const tabSearchBox = tabContent.querySelector('.tab-search-box');
    const tabSelector = tabContent.querySelector('.tab-student-selector');
    const tabOptions = Array.from(tabSelector.options);
    
    tabSearchBox.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        tabOptions.forEach(option => {
            if (option.value === '') {
                option.style.display = '';
                return;
            }
            
            const name = option.getAttribute('data-name').toLowerCase();
            const lrn = option.getAttribute('data-lrn').toLowerCase();
            
            if (name.includes(searchTerm) || lrn.includes(searchTerm)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    });
    
    tabContent.querySelector('.tab-student-selector').addEventListener('change', function() {
        if (this.value) {
            const studentName = this.options[this.selectedIndex].getAttribute('data-name');
            loadStudentToTab(tabId, this.value, studentName);
        }
    });
    
    // Add empty tab to activeTabs so it persists
    activeTabs.push({ id: tabId, studentId: null, studentName: 'Select Student' });
    saveTabs();
    
    // Switch to new tab
    switchTab(tabId);
    
    // Return the tab ID so caller can use it
    return tabId;
}

function resetMainTab() {
    // Reset main tab to initial state
    const mainTab = document.getElementById('main-tab');
    mainTab.innerHTML = `
        <div class="selection-card">
            <h5><i class="bi bi-person-circle"></i> Select Student</h5>
            <div class="mb-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="studentSearchBox" class="form-control" placeholder="Search by name or LRN...">
                </div>
            </div>
            <select id="studentSelector" class="form-select form-select-lg" size="15" style="height: auto; min-height: 400px;">
                <option value="">-- Choose a student --</option>
                <?php 
                $students->data_seek(0);
                while($s = $students->fetch_assoc()): 
                ?>
                <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['fullname']) ?>" data-lrn="<?= htmlspecialchars($s['lrn']) ?>">
                    <?= htmlspecialchars($s['fullname']) ?> (LRN: <?= htmlspecialchars($s['lrn']) ?>)
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clipboard-data" style="font-size: 48px;"></i>
            <h4 class="mt-3">Multi-Student Grade Entry</h4>
            <p>Select a student above to start entering grades</p>
            <p class="small">You can work on multiple students at the same time using tabs!</p>
        </div>
    `;
    
    // Reset tab button text
    const mainTabBtn = document.getElementById('main-tab-btn');
    mainTabBtn.innerHTML = `
        <i class="bi bi-house-door"></i>
        <span>Select Student</span>
        <span class="close-tab" id="main-tab-close">×</span>
    `;
    
    // Re-attach event listeners
    const searchBox = document.getElementById('studentSearchBox');
    const selector = document.getElementById('studentSelector');
    const allOptions = Array.from(selector.options);
    
    searchBox.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        allOptions.forEach(option => {
            if (option.value === '') {
                option.style.display = '';
                return;
            }
            
            const name = option.getAttribute('data-name').toLowerCase();
            const lrn = option.getAttribute('data-lrn').toLowerCase();
            
            if (name.includes(searchTerm) || lrn.includes(searchTerm)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    });
    
    selector.addEventListener('change', function() {
        if (this.value) {
            const studentName = this.options[this.selectedIndex].getAttribute('data-name');
            loadStudentToMainTab(this.value, studentName);
        }
    });
    
    // Re-attach close button listener
    const mainTabCloseBtn1 = document.getElementById('main-tab-close');
    if (mainTabCloseBtn1) {
        mainTabCloseBtn1.addEventListener('click', function(e) {
            e.stopPropagation();
            tabToClose = 'main-tab';
            closeTabModal.show();
        });
    }
    
    // Clear main tab saved state and form data
    sessionStorage.removeItem(storagePrefix + 'mainTabStudent');
    sessionStorage.removeItem(storagePrefix + 'tabFormData_main-tab');
    
    // Switch to main tab
    switchTab('main-tab');
}

function confirmResetMainTab() {
    // Reset main tab to initial state
    const mainTab = document.getElementById('main-tab');
    mainTab.innerHTML = `
        <div class="selection-card">
            <h5><i class="bi bi-person-circle"></i> Select Student</h5>
            <div class="mb-3">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="studentSearchBox" class="form-control" placeholder="Search by name or LRN...">
                </div>
            </div>
            <select id="studentSelector" class="form-select form-select-lg" size="15" style="height: auto; min-height: 400px;">
                <option value="">-- Choose a student --</option>
                <?php 
                $students->data_seek(0);
                while($s = $students->fetch_assoc()): 
                ?>
                <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['fullname']) ?>" data-lrn="<?= htmlspecialchars($s['lrn']) ?>">
                    <?= htmlspecialchars($s['fullname']) ?> (LRN: <?= htmlspecialchars($s['lrn']) ?>)
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clipboard-data" style="font-size: 48px;"></i>
            <h4 class="mt-3">Multi-Student Grade Entry</h4>
            <p>Select a student above to start entering grades</p>
            <p class="small">You can work on multiple students at the same time using tabs!</p>
        </div>
    `;
    
    // Reset tab button text
    const mainTabBtn = document.getElementById('main-tab-btn');
    mainTabBtn.innerHTML = `
        <i class="bi bi-house-door"></i>
        <span>Select Student</span>
        <span class="close-tab" id="main-tab-close">×</span>
    `;
    
    // Re-attach event listeners
    const searchBox = document.getElementById('studentSearchBox');
    const selector = document.getElementById('studentSelector');
    const allOptions = Array.from(selector.options);
    
    searchBox.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        allOptions.forEach(option => {
            if (option.value === '') {
                option.style.display = '';
                return;
            }
            
            const name = option.getAttribute('data-name').toLowerCase();
            const lrn = option.getAttribute('data-lrn').toLowerCase();
            
            if (name.includes(searchTerm) || lrn.includes(searchTerm)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    });
    
    selector.addEventListener('change', function() {
        if (this.value) {
            const studentName = this.options[this.selectedIndex].getAttribute('data-name');
            loadStudentToMainTab(this.value, studentName);
        }
    });
    
    // Re-attach close button listener
    const mainTabCloseBtn2 = document.getElementById('main-tab-close');
    if (mainTabCloseBtn2) {
        mainTabCloseBtn2.addEventListener('click', function(e) {
            e.stopPropagation();
            tabToClose = 'main-tab';
            closeTabModal.show();
        });
    }
    
    // Clear main tab saved state and form data
    sessionStorage.removeItem(storagePrefix + 'mainTabStudent');
    sessionStorage.removeItem(storagePrefix + 'tabFormData_main-tab');
    
    // Ensure other tabs remain saved
    saveTabs();
    
    // Switch to main tab
    switchTab('main-tab');
}

let tabToClose = null;

function closeTab(tabId) {
    if (tabId === 'main-tab') return;
    
    // Show confirmation modal
    tabToClose = tabId;
    window.closeTabModal.show();
}

function confirmCloseTabAction() {
    if (!tabToClose) return;
    
    const tabId = tabToClose;
    
    // If it's the main tab, reset it instead of closing
    if (tabId === 'main-tab') {
        confirmResetMainTab();
        tabToClose = null;
        return;
    }
    
    // Find all tab buttons
    const allTabButtons = Array.from(document.querySelectorAll('.grade-tab'));
    const currentTabButton = document.getElementById(tabId + '-btn');
    const currentIndex = allTabButtons.indexOf(currentTabButton);
    
    // Determine which tab to switch to
    let targetTab = 'main-tab';
    if (currentIndex > 0) {
        // Switch to the previous tab (the one before the closed tab)
        const previousButton = allTabButtons[currentIndex - 1];
        targetTab = previousButton.id.replace('-btn', '');
    }
    
    // Remove the tab
    document.getElementById(tabId).remove();
    document.getElementById(tabId + '-btn').remove();
    
    // Clear saved form data
    sessionStorage.removeItem(storagePrefix + 'tabFormData_' + tabId);
    
    // Switch to the target tab
    switchTab(targetTab);
    
    // Remove from active tabs
    activeTabs = activeTabs.filter(t => t.id !== tabId);
    saveTabs();
    
    tabToClose = null;
}

function loadStudentToMainTab(studentId, studentName) {
    loadGradeForm('main-tab', studentId, studentName);
    
    // Update main tab button with close button
    const mainTabBtn = document.getElementById('main-tab-btn');
    mainTabBtn.innerHTML = `
        <i class="bi bi-person-fill"></i>
        <span>${studentName}</span>
        <span class="close-tab" id="main-tab-close">×</span>
    `;
    
    // Re-attach tab switch listener
    mainTabBtn.onclick = function(e) {
        if (!e.target.classList.contains('close-tab')) {
            switchTab('main-tab');
        }
    };
    
    // Re-attach close button listener - use onclick to replace any previous listener
    const closeBtn = document.getElementById('main-tab-close');
    if (closeBtn) {
        closeBtn.onclick = function(e) {
            e.stopPropagation();
            // Check if studentSelector exists (means no student selected yet)
            const studentSelector = document.getElementById('studentSelector');
            if (studentSelector) {
                // Student selector visible means no student loaded, reset directly
                confirmResetMainTab();
            } else {
                // Grade form loaded, show modal
                tabToClose = 'main-tab';
                window.closeTabModal.show();
            }
        };
    }
    
    // Save main tab state
    sessionStorage.setItem(storagePrefix + 'mainTabStudent', JSON.stringify({
        studentId: studentId,
        studentName: studentName
    }));
}

function loadStudentToTab(tabId, studentId, studentName) {
    loadGradeForm(tabId, studentId, studentName);
    
    // Update tab button
    const tabButton = document.getElementById(tabId + '-btn');
    tabButton.innerHTML = `
        <i class="bi bi-person-fill"></i>
        <span>${studentName}</span>
        <span class="close-tab">×</span>
    `;
    
    // Re-attach close button event
    tabButton.querySelector('.close-tab').addEventListener('click', (e) => {
        e.stopPropagation();
        closeTab(tabId);
    });
    
    // Update activeTabs - find and update existing tab or add new one
    const existingTabIndex = activeTabs.findIndex(t => t.id === tabId);
    if (existingTabIndex !== -1) {
        activeTabs[existingTabIndex] = { id: tabId, studentId: studentId, studentName: studentName };
    } else {
        activeTabs.push({ id: tabId, studentId: studentId, studentName: studentName });
    }
    saveTabs();
}

// Update URL parameters when school/grade changes
function updateURLParameters(tabId) {
    if (tabId !== 'main-tab') return; // Only update URL for main tab
    
    const schoolSelect = document.getElementById(`school-select-${tabId}`);
    if (!schoolSelect || !schoolSelect.value) return;
    
    const selectedOption = schoolSelect.options[schoolSelect.selectedIndex];
    const label = selectedOption.getAttribute('data-label') || '';
    
    // Parse label: "gradeLevel - section - schoolYear..."
    const match = label.match(/^(\d+)\s*-\s*([^-]+)\s*-/);
    if (match) {
        const gradeLevel = match[1].trim();
        const section = match[2].trim();
        
        // Get student ID from URL
        const urlParams = new URLSearchParams(window.location.search);
        const studentId = urlParams.get('student_id');
        
        if (studentId) {
            // Update URL without reloading page
            const newUrl = `?student_id=${studentId}&grade_level=${gradeLevel}&section=${encodeURIComponent(section)}`;
            window.history.replaceState({}, '', newUrl);
            console.log('URL updated:', newUrl);
        }
    }
}

function loadGradeForm(tabId, studentId, studentName) {
    console.log('Loading grade form for:', {tabId, studentId, studentName});
    
    // Show loading indicator
    document.getElementById(tabId).innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">Loading student data...</p>
        </div>
    `;
    
    // Fetch school history
    fetch('grades.php?ajax=get_student&id=' + studentId)
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Received data:', data);
            renderGradeForm(tabId, studentId, studentName, data.school_history || []);
            
            // Restore form data after a short delay to ensure form is fully rendered
            setTimeout(() => {
                // Get tab content element
                const tabContent = document.getElementById(tabId);
                
                // Check for auto-select school data (either from URL or from temporary variable)
                let schoolAttendedId = null;
                let schoolYear = null;
                let gradeLevel = null;
                let section = null;
                
                // Check temporary variable first (set when coming from my_class.php)
                const autoSelectKey = 'autoSelectSchool_' + tabId;
                if (window[autoSelectKey]) {
                    schoolAttendedId = window[autoSelectKey].schoolAttendedId;
                    schoolYear = window[autoSelectKey].schoolYear;
                    console.log('Found auto-select data from variable:', window[autoSelectKey]);
                    // Clear the variable after using it
                    delete window[autoSelectKey];
                } else if (tabId === 'main-tab') {
                    // For main tab, also check URL parameters (legacy behavior)
                    const urlParams = new URLSearchParams(window.location.search);
                    schoolAttendedId = urlParams.get('school_attended_id');
                    schoolYear = urlParams.get('school_year');
                    gradeLevel = urlParams.get('grade_level');
                    section = urlParams.get('section');
                }
                
                console.log('Auto-select parameters for', tabId, ':', {schoolAttendedId, schoolYear, gradeLevel, section});
                
                // Auto-select grade level if parameters are present
                if (schoolAttendedId || schoolYear || (gradeLevel && section)) {
                    // Priority 1: Use school_attended_id if provided (most precise)
                    if (schoolAttendedId && tabContent) {
                        // Clear saved form data because auto-select will override it
                        sessionStorage.removeItem(storagePrefix + 'tabFormData_' + tabId);
                        
                        const schoolSelect = tabContent.querySelector('select[name="school_attended_id"]');
                        if (schoolSelect) {
                            console.log('Setting school_attended_id to:', schoolAttendedId);
                            console.log('Available options:', Array.from(schoolSelect.options).map(o => o.value));
                            
                            // Check if the option exists
                            const optionExists = Array.from(schoolSelect.options).some(opt => opt.value == schoolAttendedId);
                            if (optionExists) {
                                schoolSelect.value = schoolAttendedId;
                                console.log('✓ School selected, value now:', schoolSelect.value);
                                // Trigger change event to load grades
                                schoolSelect.dispatchEvent(new Event('change'));
                            } else {
                                console.warn('⚠ school_attended_id not found in dropdown options:', schoolAttendedId);
                            }
                        } else {
                            console.warn('⚠ School select dropdown not found');
                        }
                    } 
                    // Priority 2: Try to match by school_year if provided
                    else if (schoolYear && (gradeLevel || section) && tabContent) {
                        // Clear saved form data because URL will override it
                        sessionStorage.removeItem(storagePrefix + 'tabFormData_' + tabId);
                        
                        const schoolSelect = tabContent.querySelector('select[name="school_attended_id"]');
                        if (schoolSelect) {
                            // Find the option that matches school_year and optionally grade/section
                            const options = schoolSelect.querySelectorAll('option');
                            for (let option of options) {
                                const label = option.getAttribute('data-label') || '';
                                console.log('Checking option:', label);
                                
                                // Match school year first
                                if (label.includes(schoolYear)) {
                                    // If grade and section provided, match those too
                                    if (gradeLevel && section) {
                                        const pattern = new RegExp(`^${gradeLevel}\\s*-\\s*${section.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*-\\s*${schoolYear}`, 'i');
                                        if (pattern.test(label)) {
                                            console.log('Match found by year+grade+section! Setting value to:', option.value);
                                            schoolSelect.value = option.value;
                                            schoolSelect.dispatchEvent(new Event('change'));
                                            break;
                                        }
                                    } else {
                                        // Just match by school year
                                        console.log('Match found by school_year! Setting value to:', option.value);
                                        schoolSelect.value = option.value;
                                        schoolSelect.dispatchEvent(new Event('change'));
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    // Priority 3: Fall back to grade_level and section matching
                    else if (gradeLevel && section && tabContent) {
                        // Clear saved form data because URL will override it
                        sessionStorage.removeItem(storagePrefix + 'tabFormData_' + tabId);
                        
                        const schoolSelect = tabContent.querySelector('select[name="school_attended_id"]');
                        if (schoolSelect) {
                            // Find the option that matches the grade level and section
                            const options = schoolSelect.querySelectorAll('option');
                            for (let option of options) {
                                const label = option.getAttribute('data-label') || '';
                                console.log('Checking option:', label);
                                
                                // Match pattern: "gradeLevel - section - schoolYear..."
                                // Example: "1 - Diamond - 2025-2026 (Adviser: John Doe)"
                                const pattern = new RegExp(`^${gradeLevel}\\s*-\\s*${section.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*-`, 'i');
                                
                                if (pattern.test(label)) {
                                    console.log('Match found by grade+section! Setting value to:', option.value);
                                    schoolSelect.value = option.value;
                                    // Trigger change event to load grades
                                    schoolSelect.dispatchEvent(new Event('change'));
                                    break;
                                }
                            }
                        }
                    } else {
                        // No URL parameters, restore saved form data
                        restoreTabFormData(tabId);
                    }
                } else {
                    // For non-main tabs, always restore form data
                    restoreTabFormData(tabId);
                }
                
                // Load subject names and grades if school is selected after restore
                if (tabContent) {
                    let schoolSelect = tabContent.querySelector('select[name="school_attended_id"]');
                    if (schoolSelect && schoolSelect.value) {
                        console.log('Loading subject names and grades for restored tab');
                        // Use sequential loader to prevent race conditions
                        loadSubjectNamesAndGrades(tabId, studentId);
                    } else {
                        // No school selected yet, but apply tracking for any restored data
                        trackUnsavedChanges(tabId);
                    }
                
                    // Add change listener to school_attended_id to clear form data when changed
                    if (schoolSelect) {
                        schoolSelect.addEventListener('change', function() {
                            // Clear saved form data when grade level/section changes
                            sessionStorage.removeItem(storagePrefix + 'tabFormData_' + tabId);
                            
                            // Clear all grade inputs
                            tabContent.querySelectorAll('input[type="number"]').forEach(input => {
                            input.value = '';
                        });
                    });
                }
                
                // Add validation for grade inputs - prevent input if no grade level selected
                tabContent.querySelectorAll('input[type="number"].grade-input:not([readonly])').forEach(gradeInput => {
                    gradeInput.addEventListener('focus', function() {
                        const currentSchoolSelect = tabContent.querySelector('select[name="school_attended_id"]');
                        if (currentSchoolSelect && !currentSchoolSelect.value) {
                            this.blur();
                            
                            // Add red glow to dropdown
                            currentSchoolSelect.style.borderColor = '#dc3545';
                            currentSchoolSelect.style.boxShadow = '0 0 0 0.2rem rgba(220, 53, 69, 0.25)';
                            
                            // Remove existing warning if any
                            const existingWarning = currentSchoolSelect.parentElement.querySelector('.grade-level-warning');
                            if (existingWarning) {
                                existingWarning.remove();
                            }
                            
                            // Add warning message below dropdown
                            const warningDiv = document.createElement('div');
                            warningDiv.className = 'grade-level-warning';
                            warningDiv.style.cssText = 'display: flex; align-items: center; gap: 5px; margin-top: 5px; color: #dc3545; font-size: 0.875rem;';
                            warningDiv.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Please select grade level first.';
                            currentSchoolSelect.parentElement.appendChild(warningDiv);
                            
                            // Focus on dropdown
                            currentSchoolSelect.focus();
                            
                            // Remove warning and red glow when dropdown is changed
                            const removeWarning = function() {
                                currentSchoolSelect.style.borderColor = '';
                                currentSchoolSelect.style.boxShadow = '';
                                const warning = currentSchoolSelect.parentElement.querySelector('.grade-level-warning');
                                if (warning) {
                                    warning.remove();
                                }
                                currentSchoolSelect.removeEventListener('change', removeWarning);
                            };
                            currentSchoolSelect.addEventListener('change', removeWarning);
                        }
                    });
                });
                
                    tabContent.querySelectorAll('input, select, textarea').forEach(field => {
                        field.addEventListener('change', function() {
                            saveTabFormData(tabId);
                        });
                    });
                    
                    // Track unsaved changes and apply visual indicators
                    trackUnsavedChanges(tabId);
                }
            }, 100);
        })
        .catch(error => {
            console.error('Error loading student data:', error);
            document.getElementById(tabId).innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> 
                    <strong>Error loading student data:</strong> ${error.message}
                    <br><small>Please try again or contact support if the problem persists.</small>
                </div>
            `;
        });
}

// Create calculation functions for each tab dynamically
function createTabCalculations(tabId) {
    console.log('Creating calculations for tab:', tabId);
    
    // Replace hyphens with underscores for function names (JavaScript doesn't allow hyphens in identifiers)
    const safeFunctionName = tabId.replace(/-/g, '_');
    
    window['calculateFinalRating_' + safeFunctionName] = function(subjectId) {
        console.log('calculateFinalRating called for tab:', tabId, 'subject:', subjectId);
        let q1 = parseFloat(document.querySelector(`#${tabId} [name="grades[${subjectId}][q1]"]`)?.value) || 0;
        let q2 = parseFloat(document.querySelector(`#${tabId} [name="grades[${subjectId}][q2]"]`)?.value) || 0;
        let q3 = parseFloat(document.querySelector(`#${tabId} [name="grades[${subjectId}][q3]"]`)?.value) || 0;
        let q4 = parseFloat(document.querySelector(`#${tabId} [name="grades[${subjectId}][q4]"]`)?.value) || 0;

        console.log('Quarter values:', {q1, q2, q3, q4});

        let count = 0, sum = 0;
        if (q1 > 0) { sum += q1; count++; }
        if (q2 > 0) { sum += q2; count++; }
        if (q3 > 0) { sum += q3; count++; }
        if (q4 > 0) { sum += q4; count++; }
        
        if (count > 0) {
            let finalRating = Math.round(sum / count);
            document.querySelector(`#${tabId} [name="grades[${subjectId}][final_rating]"]`).value = finalRating;
            document.querySelector(`#${tabId} [name="grades[${subjectId}][remarks]"]`).value = (finalRating >= 75 ? "Passed" : "Failed");
        } else {
            document.querySelector(`#${tabId} [name="grades[${subjectId}][final_rating]"]`).value = '';
            document.querySelector(`#${tabId} [name="grades[${subjectId}][remarks]"]`).value = '';
        }
    };

    window['calculateMAPEH_' + safeFunctionName] = function() {
        // IDs: MAPEH components (Music, Arts, Physical Education, Health)
        // Detect the actual MAPEH subject id from the generated hidden field for this tab
        const mapehSubjectEl = document.getElementById(`mapehSubjectId-${tabId}`);
        const mapehSubjectId = mapehSubjectEl ? parseInt(mapehSubjectEl.value) : 8;
        const ids = [9, 10, 11, 12];
        let quarterValues = [];
        
        // First check: Are ALL MAPEH component subjects completely empty?
        let hasAnyComponentValue = false;
        for (let q = 1; q <= 4; q++) {
            ids.forEach(id => {
                let val = parseFloat(document.querySelector(`#${tabId} [name="grades[${id}][q${q}]"]`)?.value) || 0;
                if (val > 0) hasAnyComponentValue = true;
            });
        }
        
        // If no component has any value at all, clear everything and return
        if (!hasAnyComponentValue) {
            for (let q = 1; q <= 4; q++) {
                const mapehEl = document.getElementById(`mapehQ${q}-${tabId}`);
                if (mapehEl) mapehEl.innerText = "";
            }
            let finalInput = document.querySelector(`#${tabId} [name="grades[8][final_rating]"]`);
            if(finalInput){
                finalInput.value = '';
                document.querySelector(`#${tabId} [name="grades[8][remarks]"]`).value = '';
            }
            return;
        }

        // Calculate MAPEH for each quarter (average of Music, Arts, PE, Health)
        for (let q = 1; q <= 4; q++) {
            let total = 0, count = 0;
            ids.forEach(id => {
                let val = parseFloat(document.querySelector(`#${tabId} [name="grades[${id}][q${q}]"]`)?.value) || 0;
                if (val > 0) { total += val; count++; }
            });

            // Display quarterly MAPEH average (only if at least one component has a value)
            let avg = (count > 0) ? Math.round(total / count) : "";
            const mapehEl = document.getElementById(`mapehQ${q}-${tabId}`);
            if (mapehEl) mapehEl.innerText = avg;
            // Also set corresponding hidden input so quarterly MAPEH values are posted to server
            const mapehInput = document.querySelector(`#${tabId} input[name="grades[${mapehSubjectId}][q${q}]"]`);
            if (mapehInput) {
                mapehInput.value = (avg !== "") ? avg : '';
            }
            quarterValues[q-1] = parseFloat(avg) || 0;
        }

        // Calculate MAPEH Final Rating (average of quarterly MAPEH values)
        const validQuarters = quarterValues.filter(v => v > 0);
        if (validQuarters.length > 0) {
            let final = Math.round(validQuarters.reduce((a,b)=>a+b,0) / validQuarters.length);
            let finalInput = document.querySelector(`#${tabId} [name="grades[${mapehSubjectId}][final_rating]"]`);
            if(finalInput){
                finalInput.value = final;
                const remarksEl = document.querySelector(`#${tabId} [name="grades[${mapehSubjectId}][remarks]"]`);
                if(remarksEl) remarksEl.value = (final >= 75 ? "Passed" : "Failed");
            }
        } else {
            let finalInput = document.querySelector(`#${tabId} [name="grades[${mapehSubjectId}][final_rating]"]`);
            if(finalInput){
                finalInput.value = '';
                const remarksEl = document.querySelector(`#${tabId} [name="grades[${mapehSubjectId}][remarks]"]`);
                if(remarksEl) remarksEl.value = '';
            }
        }
    };

    window['calculateGeneralAverage_' + safeFunctionName] = function() {
        const skipIds = [9, 10, 11, 12];
        let quarterSums = [0, 0, 0, 0];
        let quarterCounts = [0, 0, 0, 0];
        let finalSum = 0, finalCount = 0;

        for (let q = 1; q <= 4; q++) {
            document.querySelectorAll(`#${tabId} input[name*="[q${q}]"]`).forEach(input => {
                let subjectId = parseInt(input.name.match(/\d+/)[0]);
                let val = parseFloat(input.value) || 0;
                if (val && !skipIds.includes(subjectId)) {
                    quarterSums[q-1] += val;
                    quarterCounts[q-1]++;
                }
            });
            const mapehEl = document.getElementById(`mapehQ${q}-${tabId}`);
            if (mapehEl) {
                let mapehVal = parseFloat(mapehEl.innerText) || 0;
                if(mapehVal > 0){
                    quarterSums[q-1] += mapehVal;
                    quarterCounts[q-1]++;
                }
            }
        }

        document.querySelectorAll(`#${tabId} input[name*="[final_rating]"]`).forEach(input => {
            let subjectId = parseInt(input.name.match(/\d+/)[0]);
            let val = parseFloat(input.value) || 0;
            if (val && !skipIds.includes(subjectId)) {
                finalSum += val;
                finalCount++;
            }
        });
        
        let mapehFinal = parseFloat(document.querySelector(`#${tabId} [name="grades[8][final_rating]"]`)?.value) || 0;
        if(mapehFinal > 0){
            finalSum += mapehFinal;
            finalCount++;
        }

        // Update Final Rating as percent and Remarks (PASSED/FAILED). Quarters are shown as '-' in this UI.
        const finalEl = document.getElementById(`gaFinal-${tabId}`);
        const remarksEl = document.getElementById(`gaRemarks-${tabId}`);
        if (finalEl) finalEl.textContent = finalCount ? Math.round(finalSum/finalCount) + '%' : '-';
        if (remarksEl) remarksEl.textContent = finalCount ? (Math.round(finalSum/finalCount) >= 75 ? 'PASSED' : 'FAILED') : '-';
    };
}

function renderGradeForm(tabId, studentId, studentName, schoolHistory) {
    const tabContent = document.getElementById(tabId);
    
    console.log(`🏗️ renderGradeForm called: tabId=${tabId}, studentId=${studentId}, studentName=${studentName}`);
    
    // Create calculation functions FIRST before rendering form
    createTabCalculations(tabId);
    
    // Define safeFunctionName for use in template
    const safeFunctionName = tabId.replace(/-/g, '_');
    
    let schoolOptions = '<option value="">-- Select --</option>';
    schoolHistory.forEach(school => {
        schoolOptions += `<option value="${school.id}" data-label="${school.label}">${school.label}</option>`;
    });
    
    const isAdmin = <?= json_encode($user['role'] === 'admin') ?>;
    
    tabContent.innerHTML = `
        <form method="POST" id="form-${tabId}">
            <input type="hidden" name="student_id" value="${studentId}">
            <input type="hidden" id="current-tab-id" value="${tabId}">
            
            <div class="selection-card">
                <h5><i class="bi bi-building"></i> ${studentName} - School Record</h5>
                <div class="row g-3">
                    <div class="col-md-12">
                        <select name="school_attended_id" id="school-select-${tabId}" class="form-control" onchange="updateQuarterLockButton('${tabId}'); updateURLParameters('${tabId}'); loadSubjectNamesAndGrades('${tabId}', ${studentId}, true)" required>
                            ${schoolOptions}
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Learning Areas</strong>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleSubjectEdit('${tabId}')" style="display: none;">
                    <i class="bi bi-pencil"></i> <span id="edit-subjects-text-${tabId}">Edit Subjects</span>
                </button>
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered align-middle text-center">
                    <thead class="table-primary">
                        <tr>
                            <th>Learning Areas</th>
                            <th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th>
                            <th>Final Rating</th><th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody id="subjects-${tabId}">
                        ${generateSubjectRows(tabId)}
                        <tr id="gaRow-${tabId}" class="table-warning">
                            <td><strong>General Average</strong></td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td id="gaFinal-${tabId}"><strong>-</strong></td>
                            <td id="gaRemarks-${tabId}">-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            
            
            <!-- Remedial Classes Section -->
            <div class="mt-4">
                <h5 class="mb-3"><i class="bi bi-book"></i> Remedial Classes</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr style="background-color: var(--card-bg); color: var(--content-text);">
                                <th>
                                    <span class="d-none d-sm-inline">Learning Areas</span>
                                    <span class="d-inline d-sm-none">Subject</span>
                                </th>
                                <th style="width: 90px;">
                                    <span class="d-none d-sm-inline">Final Rating</span>
                                    <span class="d-inline d-sm-none">Final</span>
                                </th>
                                <th style="width: 90px;">
                                    <span class="d-none d-sm-inline">Remedial Class Mark</span>
                                    <span class="d-inline d-sm-none">R. Mark</span>
                                </th>
                                <th style="width: 90px;">
                                    <span class="d-none d-sm-inline">Recomputed Final Grade</span>
                                    <span class="d-inline d-sm-none">Recomp.</span>
                                </th>
                                <th style="width: 85px;">
                                    <span class="d-none d-sm-inline">Remarks</span>
                                    <span class="d-inline d-sm-none">Rem.</span>
                                </th>
                                <th style="width: 120px;">
                                    <span class="d-none d-sm-inline">Conducted from</span>
                                    <span class="d-inline d-sm-none">From</span>
                                </th>
                                <th style="width: 120px;">
                                    <span class="d-none d-sm-inline">Conducted to</span>
                                    <span class="d-inline d-sm-none">To</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <select name="remedial[1][learning_area]" class="form-select remedial-subject-select" id="remedial-subject-1-${tabId}" onchange="autoFillRemedialFinalRating('${tabId}', 1)">
                                        <option value="">-- Select Subject --</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="remedial[1][final_rating]" 
                                           class="form-control" min="0" max="100" step="0.01" readonly
                                           onchange="calculateRecomputedGrade('${tabId}', 1)">
                                </td>
                                <td>
                                    <input type="number" name="remedial[1][remedial_class_mark]" 
                                           class="form-control" min="0" max="100" step="0.01"
                                           oninput="calculateRecomputedGrade('${tabId}', 1)">
                                </td>
                                <td>
                                    <input type="number" name="remedial[1][recomputed_final_grade]" 
                                           class="form-control readonly-light" min="0" max="100" step="0.01" readonly>
                                </td>
                                <td>
                                    <input type="text" name="remedial[1][remarks]" 
                                           class="form-control readonly-light" readonly>
                                </td>
                                <td>
                                    <input type="date" name="remedial[1][conducted_from]" class="form-control">
                                </td>
                                <td>
                                    <input type="date" name="remedial[1][conducted_to]" class="form-control">
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <select name="remedial[2][learning_area]" class="form-select remedial-subject-select" id="remedial-subject-2-${tabId}" onchange="autoFillRemedialFinalRating('${tabId}', 2)">
                                        <option value="">-- Select Subject --</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="remedial[2][final_rating]" 
                                           class="form-control" min="0" max="100" step="0.01" readonly
                                           onchange="calculateRecomputedGrade('${tabId}', 2)">
                                </td>
                                <td>
                                    <input type="number" name="remedial[2][remedial_class_mark]" 
                                           class="form-control" min="0" max="100" step="0.01"
                                           oninput="calculateRecomputedGrade('${tabId}', 2)">
                                </td>
                                <td>
                                    <input type="number" name="remedial[2][recomputed_final_grade]" 
                                           class="form-control readonly-light" min="0" max="100" step="0.01" readonly>
                                </td>
                                <td>
                                    <input type="text" name="remedial[2][remarks]" 
                                           class="form-control readonly-light" readonly>
                                </td>
                                <!-- conducted_from/to for remedial[2] removed; single conducted dates row is used (remedial[1]) -->
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-3">
                <button type="button" class="btn btn-primary" onclick="confirmSaveGrades('${tabId}')">
                    <i class="bi bi-save"></i> Save Grades
                </button>
                <div class="mt-2">
                    <p class="text-muted small mb-1">
                        <i class="bi bi-check-circle"></i> <strong>Auto-sync enabled!</strong> When you save grades in one tab, all other tabs with this student will update automatically.
                    </p>
                    <p class="text-muted small mb-0">
                        <span class="badge bg-success">Green glow</span> = Saved to database &nbsp;
                        <span class="badge bg-danger">Red glow</span> = Unsaved changes (persists until saved)
                    </p>
                </div>
            </div>
        </form>
    `;
    
    // Add Enter key listener to form for save confirmation
    const form = document.getElementById('form-' + tabId);
    if (form) {
        form.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                confirmSaveGrades(tabId);
            }
        });
    }
}

// Auto-refresh grades (called from storage event)
function refreshGradesAuto(tabId, studentId) {
    const schoolSelect = document.getElementById('school-select-' + tabId);
    if (!schoolSelect || !schoolSelect.value) {
        return;
    }
    
    console.log('Auto-refreshing grades for', tabId, 'student', studentId);
    
    // Clear cached grade data from sessionStorage
    const savedData = sessionStorage.getItem(storagePrefix + 'tabFormData_' + tabId);
    if (savedData) {
        const formData = JSON.parse(savedData);
        const cleanedData = formData.filter(field => !field.name?.startsWith('grades['));
        sessionStorage.setItem(storagePrefix + 'tabFormData_' + tabId, JSON.stringify(cleanedData));
    }
    
    // Reload grades from database (skip sessionStorage - show fresh DB data)
    loadSavedGrades(tabId, studentId, true);
    
    // Show subtle notification
    const tabContent = document.getElementById(tabId);
    if (tabContent) {
        const notification = document.createElement('div');
        notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
        notification.style.cssText = 'top: 80px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            <i class="bi bi-arrow-clockwise"></i> Grades updated from another tab
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
}

// Save active tab ID before form submission
function confirmSaveGrades(tabId) {
    // Set modal content
    document.getElementById('customConfirmTitle').textContent = 'Confirm Save';
    document.getElementById('customConfirmMessage').textContent = 'Are you sure you want to save these grades?';
    
    // Show the modal
    const confirmModal = new bootstrap.Modal(document.getElementById('customConfirmModal'));
    confirmModal.show();
    
    // Handle Yes button click
    const yesButton = document.getElementById('customConfirmYes');
        const handleYes = function() {
        confirmModal.hide();
        saveActiveTabBeforeSubmit(tabId);
        
        // Submit the form
        const form = document.getElementById('form-' + tabId);
        if (form) {
            // Ensure remedial[2] has conducted_from/to values if UI only shows one set (copy from remedial[1])
            try {
                const from1 = form.querySelector('[name="remedial[1][conducted_from]"]')?.value || '';
                const to1 = form.querySelector('[name="remedial[1][conducted_to]"]')?.value || '';
                if (!form.querySelector('[name="remedial[2][conducted_from]"]')) {
                    const hidFrom = document.createElement('input'); hidFrom.type = 'hidden'; hidFrom.name = 'remedial[2][conducted_from]'; hidFrom.value = from1; form.appendChild(hidFrom);
                } else {
                    form.querySelector('[name="remedial[2][conducted_from]"]').value = from1;
                }
                if (!form.querySelector('[name="remedial[2][conducted_to]"]')) {
                    const hidTo = document.createElement('input'); hidTo.type = 'hidden'; hidTo.name = 'remedial[2][conducted_to]'; hidTo.value = to1; form.appendChild(hidTo);
                } else {
                    form.querySelector('[name="remedial[2][conducted_to]"]').value = to1;
                }
            } catch (e) {
                console.error('Error ensuring remedial conducted dates:', e);
            }

            form.submit();
        }
        
        // Remove event listener after use
        yesButton.removeEventListener('click', handleYes);
    };
    
    // Remove old listener and add new one
    yesButton.replaceWith(yesButton.cloneNode(true));
    document.getElementById('customConfirmYes').addEventListener('click', handleYes);
}

function saveActiveTabBeforeSubmit(tabId) {
    sessionStorage.setItem(storagePrefix + 'activeTabBeforeSubmit', tabId);
    console.log('Saved active tab before submit:', tabId);
    
    // Mark all grade inputs as saved (will turn green after successful save)
    sessionStorage.setItem(storagePrefix + 'pendingSave_' + tabId, 'true');
}

// Update grade indicator in a specific tab (called from storage event)
function updateGradeIndicator(tabId, fieldName, value, isUnsaved) {
    const input = document.querySelector(`#${tabId} [name="${fieldName}"]`);
    if (input && input !== document.activeElement) { // Don't update if user is currently typing in this field
        input.value = value;
        input.classList.remove('grade-input-unsaved', 'grade-input-saved');
        
        if (isUnsaved) {
            input.classList.add('grade-input-unsaved');
        } else if (value) {
            input.classList.add('grade-input-saved');
        }
    }
}

// Track unsaved changes in grade inputs
function trackUnsavedChanges(tabId) {
    const gradeInputs = document.querySelectorAll(`#${tabId} input[name^="grades["]`);
    
    // Get saved grades from database to compare
    const savedGrades = sessionStorage.getItem(storagePrefix + 'savedGrades_' + tabId);
    const savedData = savedGrades ? JSON.parse(savedGrades) : {};
    
    gradeInputs.forEach(input => {
        const fieldName = input.name;
        
        // Skip subject names, final rating, and remarks - only track quarter grades
        if (fieldName.includes('subject_name') || fieldName.includes('final_rating') || fieldName.includes('remarks')) {
            return;
        }
        
        // Only add event listener if not already added
        if (input.dataset.trackingEnabled === 'true') {
            return;
        }
        
        // Mark as tracked
        input.dataset.trackingEnabled = 'true';
        
        // Store original value if not set
        if (!input.dataset.originalValue) {
            input.dataset.originalValue = input.value;
        }
        
        // Listen for changes
        input.addEventListener('input', function() {
            const currentValue = this.value;
            const originalValue = this.dataset.originalValue;
            const fieldName = this.name;
            const wasInDB = savedData[fieldName] === originalValue;
            
            // Remove both classes first
            this.classList.remove('grade-input-unsaved', 'grade-input-saved');
            
            // If value changed from original, mark as unsaved
            if (currentValue !== originalValue) {
                this.classList.add('grade-input-unsaved');
                
                // Broadcast to other tabs with same student
                const studentIdMatch = tabId === 'main-tab' 
                    ? sessionStorage.getItem(storagePrefix + 'mainTabStudent')
                    : activeTabs.find(t => t.id === tabId);
                
                if (studentIdMatch) {
                    const studentId = typeof studentIdMatch === 'string' 
                        ? JSON.parse(studentIdMatch).studentId 
                        : studentIdMatch.studentId;
                    
                    localStorage.setItem('gradeInputChanged', JSON.stringify({
                        studentId: studentId,
                        fieldName: fieldName,
                        value: currentValue,
                        isUnsaved: true,
                        timestamp: new Date().getTime()
                    }));
                    localStorage.removeItem('gradeInputChanged');
                }
            } else if (currentValue && wasInDB) {
                // If back to original and it was in DB, mark as saved
                this.classList.add('grade-input-saved');
            }
        });
    });
}

// Mark all grades as saved (call after successful form submission)
function markGradesAsSaved(tabId) {
    const gradeInputs = document.querySelectorAll(`#${tabId} input[name^="grades["]`);
    const savedGrades = {};
    
    gradeInputs.forEach(input => {
        const fieldName = input.name;
        
        // Skip subject names, final rating, and remarks - only mark quarter grades
        if (fieldName.includes('subject_name') || fieldName.includes('final_rating') || fieldName.includes('remarks')) {
            return;
        }
        
        // Remove unsaved indicator, add saved indicator
        input.classList.remove('grade-input-unsaved');
        if (input.value) {
            input.classList.add('grade-input-saved');
            // Update original value
            input.dataset.originalValue = input.value;
            savedGrades[input.name] = input.value;
        } else {
            input.classList.remove('grade-input-saved');
        }
    });
    
    // Save to sessionStorage
    sessionStorage.setItem(storagePrefix + 'savedGrades_' + tabId, JSON.stringify(savedGrades));
    sessionStorage.removeItem(storagePrefix + 'pendingSave_' + tabId);
}

// Auto-refresh grades (called from storage event)
function refreshGradesAuto(tabId, studentId) {
    const schoolSelect = document.getElementById('school-select-' + tabId);
    if (!schoolSelect || !schoolSelect.value) {
        return;
    }
    
    console.log('Auto-refreshing grades for', tabId, 'student', studentId);
    
    // Clear cached grade data from sessionStorage
    const savedData = sessionStorage.getItem(storagePrefix + 'tabFormData_' + tabId);
    if (savedData) {
        const formData = JSON.parse(savedData);
        const cleanedData = formData.filter(field => !field.name?.startsWith('grades['));
        sessionStorage.setItem(storagePrefix + 'tabFormData_' + tabId, JSON.stringify(cleanedData));
    }
    
    // Reload grades from database (skip sessionStorage - show fresh DB data)
    loadSavedGrades(tabId, studentId, true);
    
    // Show subtle notification
    const tabContent = document.getElementById(tabId);
    if (tabContent) {
        const notification = document.createElement('div');
        notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
        notification.style.cssText = 'top: 80px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            <i class="bi bi-arrow-clockwise"></i> Grades updated from another tab
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
}

// Refresh grades from database (manual button click)
function refreshGrades(tabId, studentId) {
    const schoolSelect = document.getElementById('school-select-' + tabId);
    if (!schoolSelect || !schoolSelect.value) {
        showWarning('Please select a school record first', 'Notice');
        return;
    }
    
    console.log('Refreshing grades for', tabId, 'student', studentId);
    
    // Show loading indicator
    const button = event.target;
    const originalHTML = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="bi bi-arrow-clockwise spinner-border spinner-border-sm"></i> Refreshing...';
    
    // Clear cached grade data from sessionStorage
    const savedData = sessionStorage.getItem(storagePrefix + 'tabFormData_' + tabId);
    if (savedData) {
        const formData = JSON.parse(savedData);
        const cleanedData = formData.filter(field => !field.name?.startsWith('grades['));
        sessionStorage.setItem(storagePrefix + 'tabFormData_' + tabId, JSON.stringify(cleanedData));
    }
    
    // Reload grades from database (skip sessionStorage - user wants fresh DB data)
    loadSavedGrades(tabId, studentId, true);
    
    // Restore button after a delay
    setTimeout(() => {
        button.disabled = false;
        button.innerHTML = originalHTML;
        showWarning('Grades refreshed from database', 'Success');
    }, 1000);
}

function generateSubjectRows(tabId) {
    const subjects = <?= json_encode($subjects_array) ?>;
    let html = '';
    
    // Replace hyphens with underscores for function names
    const safeFunctionName = tabId.replace(/-/g, '_');
    
    subjects.forEach(subject => {
        // Skip General Average - it's calculated separately
        if (subject.subject_name === 'General Average') return;
        
        const isMapeh = (subject.subject_name === 'MAPEH');
        const isMapehComponent = ['Music', 'Arts', 'Physical Education', 'Health'].includes(subject.subject_name);
        const subjectId = subject.id;
        
        // Make subject name editable
        html += `<tr>
            <td class="text-start">
                <input type="text" name="grades[${subjectId}][subject_name]" 
                    class="form-control fw-bold subject-name-input" 
                    value="${subject.subject_name}" 
                    readonly
                    style="border: none; background: transparent; text-align: left; cursor: default;"
                    data-original-name="${subject.subject_name}">
            </td>`;
        
        if (isMapeh) {
            // MAPEH displays auto-calculated quarterly averages from Music, Arts, PE, Health
            html += `
                <td class="mapeh-cell">
                    <div class="mapeh-cell-inner" id="mapehQ1-${tabId}"></div>
                    <input type="hidden" name="grades[${subjectId}][q1]" id="mapehInputQ1-${tabId}">
                </td>
                <td class="mapeh-cell">
                    <div class="mapeh-cell-inner" id="mapehQ2-${tabId}"></div>
                    <input type="hidden" name="grades[${subjectId}][q2]" id="mapehInputQ2-${tabId}">
                </td>
                <td class="mapeh-cell">
                    <div class="mapeh-cell-inner" id="mapehQ3-${tabId}"></div>
                    <input type="hidden" name="grades[${subjectId}][q3]" id="mapehInputQ3-${tabId}">
                </td>
                <td class="mapeh-cell">
                    <div class="mapeh-cell-inner" id="mapehQ4-${tabId}"></div>
                    <input type="hidden" name="grades[${subjectId}][q4]" id="mapehInputQ4-${tabId}">
                </td>
                <input type="hidden" id="mapehSubjectId-${tabId}" value="${subjectId}">
            `;
        } else {
            // All other subjects (including Music, Arts, PE, Health) get input fields
            for (let q = 1; q <= 4; q++) {
                const onInputEvent = isMapehComponent 
                    ? `validateGrade(this);calculateFinalRating_${safeFunctionName}(${subjectId});calculateMAPEH_${safeFunctionName}();calculateGeneralAverage_${safeFunctionName}();broadcastGradeChange('${tabId}', ${subjectId}, ${q}, this.value);`
                    : `validateGrade(this);calculateFinalRating_${safeFunctionName}(${subjectId});calculateGeneralAverage_${safeFunctionName}();broadcastGradeChange('${tabId}', ${subjectId}, ${q}, this.value);`;
                
                const onBlurEvent = isMapehComponent
                    ? `roundGrade(this);calculateFinalRating_${safeFunctionName}(${subjectId});calculateMAPEH_${safeFunctionName}();calculateGeneralAverage_${safeFunctionName}();`
                    : `roundGrade(this);calculateFinalRating_${safeFunctionName}(${subjectId});calculateGeneralAverage_${safeFunctionName}();`;
                
                html += `<td><input type="number" step="0.01" min="0" max="100" 
                    name="grades[${subjectId}][q${q}]" 
                    class="form-control grade-input quarter-${q}-input" 
                    data-quarter="${q}"
                    oninput="${onInputEvent}"
                    onblur="${onBlurEvent}"></td>`;
            }
        }
        
        html += `
            <td><input type="number" step="0.01" name="grades[${subjectId}][final_rating]" 
                class="form-control grade-input readonly-light" readonly></td>
            <td><input type="text" name="grades[${subjectId}][remarks]" 
                class="form-control grade-input readonly-light" readonly></td>
        </tr>`;
    });
    
    // Populate remedial subject dropdowns with subjects from grade table
    setTimeout(() => {
        populateRemedialSubjects(tabId);
    }, 100);
    
    return html;
}

function populateRemedialSubjects(tabId) {
    // Get subjects from the actual grade table inputs
    const subjectInputs = document.querySelectorAll(`#${tabId} input[name*="[subject_name]"]`);
    
    // MAPEH component subjects that should not appear in remedial dropdown
    const mapehComponents = ['Music', 'Arts', 'Physical Education', 'Health'];
    
    // Build options from rendered subjects
    let optionsHtml = '<option value="">-- Select Subject --</option>';
    
    subjectInputs.forEach(input => {
        const subjectName = input.value;
        const fieldName = input.name; // e.g., "grades[1][subject_name]"
        const subjectId = fieldName.match(/grades\[(\d+)\]/)?.[1];
        
        // Skip MAPEH components - only show MAPEH as a single subject
        if (mapehComponents.includes(subjectName)) {
            return;
        }
        
        if (subjectName && subjectId) {
            optionsHtml += `<option value="${subjectName}" data-subject-id="${subjectId}">${subjectName}</option>`;
        }
    });
    
    // Populate both remedial subject dropdowns
    const select1 = document.getElementById(`remedial-subject-1-${tabId}`);
    const select2 = document.getElementById(`remedial-subject-2-${tabId}`);
    
    if (select1) select1.innerHTML = optionsHtml;
    if (select2) select2.innerHTML = optionsHtml;
    
    console.log('Remedial subjects populated for', tabId);
}

// Auto-fill final rating when remedial subject is selected
function autoFillRemedialFinalRating(tabId, remedialNum) {
    const select = document.getElementById(`remedial-subject-${remedialNum}-${tabId}`);
    const finalRatingInput = document.querySelector(`#${tabId} input[name="remedial[${remedialNum}][final_rating]"]`);
    const remedialMarkInput = document.querySelector(`#${tabId} input[name="remedial[${remedialNum}][remedial_class_mark]"]`);
    const recomputedInput = document.querySelector(`#${tabId} input[name="remedial[${remedialNum}][recomputed_final_grade]"]`);
    const remarksInput = document.querySelector(`#${tabId} input[name="remedial[${remedialNum}][remarks]"]`);
    
    console.log(`autoFillRemedialFinalRating called: tabId=${tabId}, remedialNum=${remedialNum}`);
    console.log('  Select element:', select);
    console.log('  Final rating input:', finalRatingInput);
    
    if (select && finalRatingInput) {
        const selectedOption = select.options[select.selectedIndex];
        const subjectName = selectedOption?.value;
        const subjectId = selectedOption?.getAttribute('data-subject-id');
        
        console.log('  Selected option:', selectedOption);
        console.log('  Subject Name:', subjectName);
        console.log('  Subject ID:', subjectId);
        
        if (subjectId) {
            // Get the final rating directly from the grades table input
            const gradeFinalRating = document.querySelector(`#${tabId} input[name="grades[${subjectId}][final_rating]"]`);
            const finalRating = gradeFinalRating?.value || '';
            
            console.log(`  Grade final rating input:`, gradeFinalRating);
            console.log(`  Final rating value: "${finalRating}"`);
            
            finalRatingInput.value = finalRating;
            
            // Reset remedial mark and calculated fields when subject changes
            if (remedialMarkInput) remedialMarkInput.value = '';
            if (recomputedInput) recomputedInput.value = '';
            if (remarksInput) remarksInput.value = '';
            
            console.log('  Reset remedial mark and calculated fields');
        } else {
            // Clear all fields if no subject selected
            finalRatingInput.value = '';
            if (remedialMarkInput) remedialMarkInput.value = '';
            if (recomputedInput) recomputedInput.value = '';
            if (remarksInput) remarksInput.value = '';
            console.log('  No subject selected, cleared all fields');
        }
    } else {
        console.error('  Missing elements! select or finalRatingInput is null');
    }
}

// Sequential loader to prevent race conditions
async function loadSubjectNamesAndGrades(tabId, studentId, skipSessionRestore = false) {
    try {
        console.log('Loading subjects and grades sequentially for tab:', tabId);
        
        // If switching schools, clear remedial data from sessionStorage
        if (skipSessionRestore) {
            const savedFormData = sessionStorage.getItem(storagePrefix + 'tabFormData_' + tabId);
            if (savedFormData) {
                const formData = JSON.parse(savedFormData);
                // Remove all remedial-related fields from sessionStorage
                const filteredData = formData.filter(field => !field.name || !field.name.startsWith('remedial['));
                sessionStorage.setItem(storagePrefix + 'tabFormData_' + tabId, JSON.stringify(filteredData));
                console.log('Cleared remedial data from sessionStorage when switching schools');
            }
        }
        
        // Step 1: Load subject names first
        await loadSubjectNames(tabId, studentId);
        
        // Step 2: Load saved grades after subjects are loaded
        await loadSavedGrades(tabId, studentId, skipSessionRestore);
        
        // Step 3: Apply quarter locks last
        loadQuarterLocks(tabId);
    } catch (error) {
        console.error('Error in loadSubjectNamesAndGrades:', error);
    }
}

// Load subject names for a specific student and school record
async function loadSubjectNames(tabId, studentId) {
    const schoolSelect = document.getElementById(`school-select-${tabId}`);
    const schoolAttendedId = schoolSelect?.value;
    
    if (!schoolAttendedId) return;
    
    try {
        const response = await fetch(`?ajax=get_subject_names&student_id=${studentId}&school_attended_id=${schoolAttendedId}`);
        const data = await response.json();
        
        console.log('Subject names loaded for tab', tabId, ':', data);
        
        if (data.success) {
            // Store subject data globally for this tab
            if (!window.tabSubjectData) window.tabSubjectData = {};
            window.tabSubjectData[tabId] = {
                subjects: data.subjects,
                is_transfer: Boolean(data.is_transfer), // Ensure boolean
                grade_level: data.grade_level,
                grade_group: data.grade_group,
                studentId: studentId,
                schoolAttendedId: schoolAttendedId
            };
            
            // Update the subject name inputs with loaded names
            const isTransfer = data.is_transfer === true || data.is_transfer === 1;
            console.log(`Updating ${data.subjects.length} subject names for tab ${tabId}, is_transfer=${isTransfer}`);
            data.subjects.forEach(subject => {
                const input = document.querySelector(`#${tabId} input[name="grades[${subject.id}][subject_name]"]`);
                if (input) {
                    console.log(`  Subject ${subject.id}: "${input.value}" → "${subject.subject_name}"`);
                    input.value = subject.subject_name;
                    input.setAttribute('data-original-name', subject.original_name);
                    
                    // Enable editing for transfer students, disable for regular students
                    if (isTransfer) {
                        // Remove any existing click listeners first by cloning
                        const newInput = input.cloneNode(true);
                        input.parentNode.replaceChild(newInput, input);
                        
                        // Make editable for transfer students
                        newInput.removeAttribute('readonly');
                        newInput.classList.remove('readonly-input');
                        newInput.style.border = '1px solid #495057';
                        newInput.style.background = '#ffffff';
                        newInput.style.cursor = 'text';
                        newInput.style.color = '#000000';
                        newInput.style.pointerEvents = 'auto';
                        // If the saved custom name is empty, show an empty value (no placeholder)
                        newInput.placeholder = '';
                        console.log(`  ✓ Subject ${subject.id} is EDITABLE (transfer student)`);
                    } else {
                        // Remove any existing click listeners first by cloning
                        const newInput = input.cloneNode(true);
                        input.parentNode.replaceChild(newInput, input);
                        
                        // Make non-clickable for regular students
                        newInput.setAttribute('readonly', true);
                        newInput.classList.add('readonly-input');
                        newInput.style.border = 'none';
                        newInput.style.background = 'transparent';
                        newInput.style.cursor = 'default';
                        newInput.style.color = 'inherit';
                        newInput.style.pointerEvents = 'none';
                        newInput.placeholder = '';
                        console.log(`  ✓ Subject ${subject.id} is READONLY (regular student)`);
                    }
                }
            });
            
            // Quarter locks will be applied by loadSubjectNamesAndGrades
            console.log('Subject names updated for tab', tabId);
        }
    } catch (error) {
        console.error('Error loading subject names:', error);
    }
}

// No longer needed - inputs enabled/disabled automatically based on transfer status

// Validate grade input (0-100 only)
function validateGrade(input) {
    if (input.value !== '') {
        let value = parseFloat(input.value);
        
        // Only validate range, don't round yet
        if (value < 0) {
            input.value = 0;
            input.style.borderColor = '#dc3545';
            setTimeout(() => input.style.borderColor = '', 1500);
        } else if (value > 100) {
            input.value = 100;
            input.style.borderColor = '#dc3545';
            setTimeout(() => input.style.borderColor = '', 1500);
        } else {
            input.style.borderColor = '';
        }
    }
}

function roundGrade(input) {
    if (input.value !== '') {
        let value = parseFloat(input.value);
        
        // Round to whole number
        value = Math.round(value);
        
        // Validate range
        if (value < 0) {
            value = 0;
        } else if (value > 100) {
            value = 100;
        }
        
        // Set the rounded value
        input.value = value;
    }
}

// Add keyboard navigation for arrow keys
document.addEventListener('keydown', function(e) {
    if (e.target.matches('input[type="number"].grade-input')) {
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault(); // Prevent default increment/decrement
            
            const currentInput = e.target;
            const currentCell = currentInput.closest('td');
            const currentRow = currentInput.closest('tr');
            const currentCellIndex = Array.from(currentRow.cells).indexOf(currentCell);
            
            function findNextInputRow(startRow, direction) {
                let row = direction === 'up' ? startRow.previousElementSibling : startRow.nextElementSibling;
                
                while (row) {
                    const targetCell = row.cells[currentCellIndex];
                    if (targetCell) {
                        const targetInput = targetCell.querySelector('input[type="number"]:not([readonly])');
                        if (targetInput) {
                            return targetInput;
                        }
                    }
                    // Skip to next row if current row doesn't have input (like MAPEH parent row)
                    row = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
                }
                return null;
            }
            
            if (e.key === 'ArrowUp') {
                const targetInput = findNextInputRow(currentRow, 'up');
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            } else if (e.key === 'ArrowDown') {
                const targetInput = findNextInputRow(currentRow, 'down');
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        }
    }
});

// Custom Modal Functions
function showWarning(message, title = 'Notice') {
    document.getElementById('customWarningTitle').textContent = title;
    document.getElementById('customWarningMessage').textContent = message;
    const modalElement = document.getElementById('customWarningModal');
    const modal = new bootstrap.Modal(modalElement, {
        backdrop: true,
        keyboard: true
    });
    modal.show();
    
    // Ensure backdrop appears on top of Quarter Lock modal
    setTimeout(() => {
        const backdrop = document.querySelector('.modal-backdrop:last-of-type');
        if (backdrop) {
            backdrop.style.zIndex = '1055';
        }
    }, 10);
}

function showConfirm(message, title = 'Confirm Action') {
    return new Promise((resolve) => {
        document.getElementById('customConfirmTitle').textContent = title;
        document.getElementById('customConfirmMessage').textContent = message;
        const modalElement = document.getElementById('customConfirmModal');
        const modal = new bootstrap.Modal(modalElement, {
            backdrop: true,
            keyboard: true
        });
        
        const yesBtn = document.getElementById('customConfirmYes');
        const newYesBtn = yesBtn.cloneNode(true);
        yesBtn.parentNode.replaceChild(newYesBtn, yesBtn);
        
        newYesBtn.addEventListener('click', () => {
            modal.hide();
            resolve(true);
        });
        
        modalElement.addEventListener('hidden.bs.modal', () => {
            resolve(false);
        }, { once: true });
        
        modal.show();
        
        // Ensure backdrop appears on top of Quarter Lock modal
        setTimeout(() => {
            const backdrop = document.querySelector('.modal-backdrop:last-of-type');
            if (backdrop) {
                backdrop.style.zIndex = '1055';
            }
        }, 10);
    });
}





// Load saved grades from database
async function loadSavedGrades(tabId, studentId, skipSessionRestore = false) {
    console.log(`📥 loadSavedGrades called for ${tabId}, skipSessionRestore=${skipSessionRestore}`);
    
    try {
        const schoolSelect = document.getElementById('school-select-' + tabId);
        const schoolAttendedId = schoolSelect ? schoolSelect.value : null;
        
        if (!schoolAttendedId) {
            return;
        }
        
        const response = await fetch(`grades.php?ajax=load_grades&student_id=${studentId}&school_attended_id=${schoolAttendedId}`);
        const data = await response.json();
        
        console.log(`📦 Database returned ${Object.keys(data.grades || {}).length} subjects for ${tabId}`);
        
        if (data.success) {
                // Populate grade inputs from database
                Object.keys(data.grades).forEach(subjectId => {
                    const gradeData = data.grades[subjectId];
                    
                    // Populate quarters
                    for (let q = 1; q <= 4; q++) {
                        const input = document.querySelector(`#${tabId} [name="grades[${subjectId}][q${q}]"]`);
                        if (input) {
                            const dbValue = gradeData['q' + q] || '';
                            input.value = dbValue;
                            if (dbValue === '' && q === 1 && subjectId == 1) {
                                console.log(`✓ Set Subject ${subjectId} Q${q} = "${dbValue}" (empty from DB)`);
                            }
                        }
                    }
                    
                    // Populate final rating and remarks
                    const finalInput = document.querySelector(`#${tabId} [name="grades[${subjectId}][final_rating]"]`);
                    const remarksInput = document.querySelector(`#${tabId} [name="grades[${subjectId}][remarks]"]`);
                    
                    if (finalInput && gradeData.final_rating) {
                        finalInput.value = gradeData.final_rating;
                        // Only set remarks if there's a final rating
                        if (remarksInput && gradeData.remarks) {
                            remarksInput.value = gradeData.remarks;
                        }
                    } else {
                        // Clear final rating and remarks if no data
                        if (finalInput) finalInput.value = '';
                        if (remarksInput) remarksInput.value = '';
                    }
                });
                
                // Recalculate MAPEH and General Average
                const safeFunctionName = tabId.replace(/-/g, '_');
                if (window['calculateMAPEH_' + safeFunctionName]) {
                    window['calculateMAPEH_' + safeFunctionName]();
                }
                if (window['calculateGeneralAverage_' + safeFunctionName]) {
                    window['calculateGeneralAverage_' + safeFunctionName]();
                }
                
                // Store database values BEFORE restoring sessionStorage
                const dbGrades = {};
                const allGradeInputs = document.querySelectorAll(`#${tabId} input[name^="grades["]`);
                allGradeInputs.forEach(input => {
                    if (input.value) {
                        dbGrades[input.name] = input.value;
                    }
                });
                
                // Get current sessionStorage values AFTER form is loaded
                const savedFormData = sessionStorage.getItem(storagePrefix + 'tabFormData_' + tabId);
                const sessionData = savedFormData ? JSON.parse(savedFormData) : [];
                const sessionGrades = {};
                sessionData.forEach(field => {
                    if (field.name && field.name.startsWith('grades[') && field.value) {
                        sessionGrades[field.name] = field.value;
                    }
                });
                
                // Only restore sessionStorage if not explicitly skipped (e.g., after save from another tab)
                if (!skipSessionRestore) {
                    // Restore unsaved changes from sessionStorage (if any)
                    // These will override database values to preserve user input
                    // EXCEPT subject names - those should always come from loadSubjectNames()
                    Object.keys(sessionGrades).forEach(fieldName => {
                        // Skip subject_name fields - they come from global subject config
                        if (fieldName.includes('[subject_name]')) return;
                        
                        const input = document.querySelector(`#${tabId} [name="${fieldName}"]`);
                        if (input) {
                            input.value = sessionGrades[fieldName];
                            if (fieldName.includes('q1') && sessionGrades[fieldName] !== '') {
                                console.log(`🔄 Restored from session: ${fieldName} = ${sessionGrades[fieldName]}`);
                            }
                        }
                    });
                } else {
                    console.log(`⏭️ Skipping sessionStorage restore for ${tabId} - showing database values only`);
                }
                
                // Recalculate again after restoring session data (or after skipping)
                if (window['calculateMAPEH_' + safeFunctionName]) {
                    window['calculateMAPEH_' + safeFunctionName]();
                }
                if (window['calculateGeneralAverage_' + safeFunctionName]) {
                    window['calculateGeneralAverage_' + safeFunctionName]();
                }
                
                // Wait a bit for calculations to complete, then populate remedial
                setTimeout(() => {
                    // Repopulate remedial subjects now that final ratings are calculated
                    populateRemedialSubjects(tabId);
                    
                    // Only restore remedial data from sessionStorage if NOT skipping session restore
                    // (skipSessionRestore = true means we're switching schools and want fresh data)
                    let remedialDataFromSession = {};
                    
                    if (!skipSessionRestore) {
                        const savedFormData = sessionStorage.getItem(storagePrefix + 'tabFormData_' + tabId);
                        if (savedFormData) {
                            const formData = JSON.parse(savedFormData);
                            formData.forEach(field => {
                                if (field.name && field.name.startsWith('remedial[')) {
                                    remedialDataFromSession[field.name] = field.value;
                                }
                            });
                        }
                        console.log('Remedial data from sessionStorage:', remedialDataFromSession);
                    } else {
                        console.log('Skipping remedial sessionStorage restore - loading from database only');
                    }
                    
                    // Populate remedial classes from database OR sessionStorage
                    const hasDbRemedial = data.remedial && data.remedial.length > 0;
                    
                    for (let num = 1; num <= 2; num++) {
                        const subjectSelect = document.querySelector(`#remedial-subject-${num}-${tabId}`);
                        const finalRating = document.querySelector(`#${tabId} input[name="remedial[${num}][final_rating]"]`);
                        const remedialMark = document.querySelector(`#${tabId} input[name="remedial[${num}][remedial_class_mark]"]`);
                        const conductedFrom = document.querySelector(`#${tabId} input[name="remedial[${num}][conducted_from]"]`);
                        const conductedTo = document.querySelector(`#${tabId} input[name="remedial[${num}][conducted_to]"]`);
                        
                        // Try to restore from sessionStorage first (for unsaved changes)
                        const sessionSubject = remedialDataFromSession[`remedial[${num}][learning_area]`];
                        const sessionMark = remedialDataFromSession[`remedial[${num}][remedial_class_mark]`];
                        const sessionFrom = remedialDataFromSession[`remedial[${num}][conducted_from]`];
                        const sessionTo = remedialDataFromSession[`remedial[${num}][conducted_to]`];
                        
                        if (sessionSubject) {
                            // Restore from sessionStorage
                            if (subjectSelect) {
                                subjectSelect.value = sessionSubject;
                                autoFillRemedialFinalRating(tabId, num);
                            }
                            if (remedialMark) remedialMark.value = sessionMark || '';
                            if (conductedFrom) conductedFrom.value = sessionFrom || '';
                            if (conductedTo) conductedTo.value = sessionTo || '';
                            
                            // Recalculate after restoring remedial mark
                            if (sessionMark) {
                                calculateRecomputedGrade(tabId, num);
                            }
                            
                            console.log(`Restored remedial ${num} from sessionStorage: subject=${sessionSubject}`);
                        } else if (hasDbRemedial && data.remedial[num - 1]) {
                            // Restore from database
                            const remedial = data.remedial[num - 1];
                            
                            if (subjectSelect && remedial.learning_area) {
                                // The value is now the subject name directly
                                subjectSelect.value = remedial.learning_area;
                                console.log(`Restored remedial ${num} from DB: ${remedial.learning_area}`);
                                autoFillRemedialFinalRating(tabId, num);
                            }
                            
                            if (remedialMark) remedialMark.value = remedial.remedial_class_mark || '';
                            if (conductedFrom) conductedFrom.value = remedial.conducted_from || '';
                            if (conductedTo) conductedTo.value = remedial.conducted_to || '';
                            
                            // Recalculate after loading remedial mark
                            if (remedial.remedial_class_mark) {
                                calculateRecomputedGrade(tabId, num);
                            }
                        } else {
                            // No data from sessionStorage or database - clear all fields
                            if (subjectSelect) subjectSelect.value = '';
                            if (finalRating) finalRating.value = '';
                            if (remedialMark) remedialMark.value = '';
                            if (conductedFrom) conductedFrom.value = '';
                            if (conductedTo) conductedTo.value = '';
                            
                            // Also clear calculated fields
                            const recomputed = document.querySelector(`#${tabId} input[name="remedial[${num}][recomputed_final_grade]"]`);
                            const remarks = document.querySelector(`#${tabId} input[name="remedial[${num}][remarks]"]`);
                            if (recomputed) recomputed.value = '';
                            if (remarks) remarks.value = '';
                            
                            console.log(`Cleared remedial ${num} - no data found`);
                        }
                    }
                }, 100);
                
                // Quarter locks will be applied by loadSubjectNamesAndGrades
                // (removed to prevent duplicate calls)
                
                // Save these grades as the "original saved" values for comparison
                const savedGrades = {};
                const gradeInputs = document.querySelectorAll(`#${tabId} input[name^="grades["]`);
                gradeInputs.forEach(input => {
                    const fieldName = input.name;
                    
                    // Skip subject names, final rating, and remarks - only track quarter grades
                    if (fieldName.includes('subject_name') || fieldName.includes('final_rating') || fieldName.includes('remarks')) {
                        return;
                    }
                    
                    const currentValue = input.value; // Current value (after sessionStorage restore)
                    const dbValue = dbGrades[fieldName] || ''; // Value from database
                    
                    if (currentValue !== dbValue) {
                        // Has unsaved changes - mark as unsaved (red)
                        input.dataset.originalValue = dbValue;
                        input.classList.remove('grade-input-saved');
                        input.classList.add('grade-input-unsaved');
                    } else if (currentValue) {
                        // Saved in database - mark as saved (green)
                        savedGrades[fieldName] = currentValue;
                        input.dataset.originalValue = currentValue;
                        input.classList.remove('grade-input-unsaved');
                        input.classList.add('grade-input-saved');
                    } else {
                        // Empty field
                        input.dataset.originalValue = '';
                        input.classList.remove('grade-input-unsaved', 'grade-input-saved');
                    }
                });
                sessionStorage.setItem(storagePrefix + 'savedGrades_' + tabId, JSON.stringify(savedGrades));
                
                // Now apply tracking for future changes
                trackUnsavedChanges(tabId);
        }
    } catch (error) {
        console.error('Error loading saved grades:', error);
    }
}

// Remedial Classes Management
function calculateRecomputedGrade(tabId, remedialNum) {
    const finalRatingInput = document.querySelector(`#${tabId} input[name="remedial[${remedialNum}][final_rating]"]`);
    const remedialMarkInput = document.querySelector(`#${tabId} input[name="remedial[${remedialNum}][remedial_class_mark]"]`);
    const recomputedInput = document.querySelector(`#${tabId} input[name="remedial[${remedialNum}][recomputed_final_grade]"]`);
    const remarksInput = document.querySelector(`#${tabId} input[name="remedial[${remedialNum}][remarks]"]`);
    
    const finalRating = parseFloat(finalRatingInput?.value) || 0;
    const remedialMark = parseFloat(remedialMarkInput?.value) || 0;
    
    console.log(`calculateRecomputedGrade: finalRating=${finalRating}, remedialMark=${remedialMark}`);
    
    if (finalRating > 0 && remedialMark > 0) {
        const recomputed = Math.round((finalRating + remedialMark) / 2);
        
        if (recomputedInput) recomputedInput.value = recomputed;
        if (remarksInput) remarksInput.value = recomputed >= 75 ? 'Passed' : 'Failed';
        
        console.log(`  Calculated: recomputed=${recomputed}, remarks=${recomputed >= 75 ? 'Passed' : 'Failed'}`);
    } else {
        // Clear if either value is missing
        if (recomputedInput) recomputedInput.value = '';
        if (remarksInput) remarksInput.value = '';
        console.log('  Cleared recomputed values (missing input)');
    }
}

function getSubjectOptions() {
    const subjects = <?= json_encode($subjects_array) ?>;
    return subjects
        .filter(s => s.subject_name !== 'General Average')
        .map(s => `<option value="${s.subject_name}">${s.subject_name}</option>`)
        .join('');
}

// Quarter Lock Management Functions
let currentLockTabId = null;
let currentLockSchoolId = null;

function updateQuarterLockButton(tabId) {
    const schoolSelect = document.getElementById('school-select-' + tabId);
    const lockBtn = document.getElementById('quarterLockBtn');
    
    if (!lockBtn) return; // Not admin
    
    // Update current school ID
    if (schoolSelect && schoolSelect.value) {
        currentLockSchoolId = schoolSelect.value;
    } else {
        currentLockSchoolId = null;
    }
    
    // Keep button visible since student is already loaded
    currentLockTabId = tabId;
}

function openQuarterLockModalFromHeader() {
    // Load global quarter locks
    loadQuarterLocksForModal();
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('quarterLockModal'));
    modal.show();
}

// Open School Subjects Management Modal
function openSchoolSubjectsModal() {
    loadSchoolSubjects();
    const modal = new bootstrap.Modal(document.getElementById('schoolSubjectsModal'));
    modal.show();
}

// Load school subjects for both grade groups
async function loadSchoolSubjects() {
    try {
        const response = await fetch('?ajax=get_school_subjects');
        const data = await response.json();
        
        if (data.success) {
            // Render subjects for each grade (1-6)
            for (let grade = 1; grade <= 6; grade++) {
                renderSubjectsList(grade, data.subjects[grade] || []);
            }
        } else {
            alert('Error: ' + (data.message || 'Failed to load school subjects'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to load school subjects. Please check console for details.');
    }
}

// Render subjects list for a specific grade level
function renderSubjectsList(gradeLevel, subjects) {
    const container = document.getElementById(`subjects-grade-${gradeLevel}-list`);
    
    if (!subjects || subjects.length === 0) {
        container.innerHTML = '<p class="text-muted">No subjects configured for this grade level.</p>';
        return;
    }
    
    let html = '<div class="list-group">';
    subjects.forEach((subject, index) => {
        if (subject.subject_name === 'General Average') return; // Skip General Average
        
        html += `
            <div class="list-group-item">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <label class="form-label mb-0"><strong>${subject.original_name}</strong></label>
                        <small class="text-muted d-block">Original subject name</small>
                    </div>
                    <div class="col-md-8">
                        <input type="text" 
                               class="form-control school-subject-input" 
                               data-grade-level="${gradeLevel}"
                               data-subject-id="${subject.subject_id}"
                               value="${subject.subject_name}"
                               placeholder="Enter display name for this subject">
                    </div>
                </div>
            </div>`;
    });
    html += '</div>';
    
    container.innerHTML = html;
}

// Save school subjects
async function saveSchoolSubjects() {
    const inputs = document.querySelectorAll('.school-subject-input');
    const updates = [];
    
    inputs.forEach(input => {
        updates.push({
            grade_level: parseInt(input.dataset.gradeLevel),
            subject_id: input.dataset.subjectId,
            subject_name: input.value.trim()
        });
    });
    
    if (updates.length === 0) {
        alert('No subjects to save');
        return;
    }
    
    try {
        const response = await fetch('?ajax=save_school_subjects', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ subjects: updates })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Broadcast to all tabs that subject names were updated
            const updatedGrades = [...new Set(updates.map(u => u.grade_level))];
            updatedGrades.forEach(gradeLevel => {
                localStorage.setItem('subjectNamesUpdated', JSON.stringify({
                    gradeLevel: gradeLevel,
                    timestamp: new Date().getTime()
                }));
                localStorage.removeItem('subjectNamesUpdated');
            });
            
            // Close the subjects modal
            bootstrap.Modal.getInstance(document.getElementById('schoolSubjectsModal')).hide();
            
            // Show success modal
            document.getElementById('successMessage').textContent = 'School subjects updated successfully!';
            const successModal = new bootstrap.Modal(document.getElementById('successModal'));
            successModal.show();
        } else {
            alert('Error saving subjects: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to save school subjects');
    }
}

function openQuarterLockModal(tabId) {
    currentLockTabId = tabId;
    const schoolSelect = document.getElementById('school-select-' + tabId);
    const schoolId = schoolSelect.value;
    
    if (!schoolId) {
        alert('Please select a school record first');
        return;
    }
    
    currentLockSchoolId = schoolId;
    
    // Load current lock states
    fetch(`grades.php?ajax=get_quarter_locks&school_attended_id=${schoolId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Received quarter lock data:', data);
            
            if (!data.locks) {
                throw new Error('Invalid response format');
            }
            
            // Show warning if there was an error but we got default locks
            if (!data.success && data.error) {
                console.warn('Quarter lock warning:', data.error);
                alert('Note: ' + data.error + '\n\nShowing default unlocked state.');
            }
            
            // Update toggle switches and labels
            for (let q = 1; q <= 4; q++) {
                const checkbox = document.getElementById(`lock-q${q}`);
                const label = document.getElementById(`lock-q${q}-label`);
                if (checkbox) {
                    checkbox.checked = data.locks['q' + q] || false;
                    if (label) {
                        label.textContent = checkbox.checked ? 'Locked' : 'Unlocked';
                        label.className = checkbox.checked ? 'text-danger fw-bold' : 'text-success';
                    }
                }
                
                // Load auto-lock time
                const autoLockInput = document.getElementById(`autoLockQ${q}`);
                if (autoLockInput && data.auto_locks && data.auto_locks['q' + q]) {
                    autoLockInput.value = data.auto_locks['q' + q];
                    updateClearButton(q, 'lock');
                } else if (autoLockInput) {
                    autoLockInput.value = '';
                    updateClearButton(q, 'lock');
                }
                
                // Load auto-unlock time
                const autoUnlockInput = document.getElementById(`autoUnlockQ${q}`);
                if (autoUnlockInput && data.auto_unlocks && data.auto_unlocks['q' + q]) {
                    autoUnlockInput.value = data.auto_unlocks['q' + q];
                    updateClearButton(q, 'unlock');
                } else if (autoUnlockInput) {
                    autoUnlockInput.value = '';
                    updateClearButton(q, 'unlock');
                }
            }
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('quarterLockModal'));
            modal.show();
        })
        .catch(error => {
            console.error('Error loading quarter locks:', error);
            showWarning('Failed to load quarter lock status: ' + error.message, 'Error');
        });
}

function loadQuarterLocksForModal() {
    // Load global quarter lock states
    fetch(`grades.php?ajax=get_quarter_locks`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Received quarter lock data:', data);
            
            if (!data.locks) {
                throw new Error('Invalid response format');
            }
            
            // Show warning if there was an error but we got default locks
            if (!data.success && data.error) {
                console.warn('Quarter lock warning:', data.error);
            }
            
            // Update toggle switches and labels
            for (let q = 1; q <= 4; q++) {
                const checkbox = document.getElementById(`lock-q${q}`);
                const label = document.getElementById(`lock-q${q}-label`);
                const isLocked = data.locks['q' + q] || false;
                
                console.log(`📋 Loading Q${q} state: locked=${isLocked} (raw value from server: ${data.locks['q' + q]})`);
                
                if (checkbox) {
                    checkbox.checked = isLocked;
                    if (label) {
                        label.textContent = checkbox.checked ? 'Locked' : 'Unlocked';
                        label.className = checkbox.checked ? 'text-danger fw-bold' : 'text-success';
                    }
                }
                
                // Load auto-lock times
                const autoLockInput = document.getElementById(`autoLockQ${q}`);
                if (data.auto_locks && data.auto_locks['q' + q]) {
                    autoLockInput.value = data.auto_locks['q' + q];
                    updateClearButton(q, 'lock');
                } else if (autoLockInput) {
                    autoLockInput.value = '';
                    updateClearButton(q, 'lock');
                }
                
                // Load auto-unlock times
                const autoUnlockInput = document.getElementById(`autoUnlockQ${q}`);
                if (data.auto_unlocks && data.auto_unlocks['q' + q]) {
                    autoUnlockInput.value = data.auto_unlocks['q' + q];
                    updateClearButton(q, 'unlock');
                } else if (autoUnlockInput) {
                    autoUnlockInput.value = '';
                    updateClearButton(q, 'unlock');
                }
            }
        })
        .catch(error => {
            console.error('Error loading quarter locks:', error);
            showWarning('Failed to load quarter lock status: ' + error.message, 'Error');
        });
}

function updateClearButton(quarter, type) {
    const input = document.getElementById(`auto${type === 'lock' ? 'Lock' : 'Unlock'}Q${quarter}`);
    const button = document.getElementById(`clear${type === 'lock' ? 'Lock' : 'Unlock'}Q${quarter}`);
    
    if (button && input) {
        if (input.value) {
            button.className = 'btn btn-danger btn-sm';
        } else {
            button.className = 'btn btn-outline-secondary btn-sm';
        }
    }
}

async function toggleQuarterLock(quarter) {
    const checkbox = document.getElementById(`lock-q${quarter}`);
    const label = document.getElementById(`lock-q${quarter}-label`);
    const locked = checkbox.checked ? 1 : 0;
    
    const action = locked ? 'lock' : 'unlock';
    const confirmed = await showConfirm(`Are you sure you want to ${action} Quarter ${quarter}?`, 'Confirm ' + (locked ? 'Lock' : 'Unlock'));
    
    if (!confirmed) {
        checkbox.checked = !checkbox.checked;
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=toggle_quarter_lock&quarter=${quarter}&locked=${locked}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('Toggle quarter lock response:', data);
        if (data.success) {
            // Update label
            if (label) {
                label.textContent = checkbox.checked ? 'Locked' : 'Unlocked';
                label.className = checkbox.checked ? 'text-danger fw-bold' : 'text-success';
            }
            
            console.log(`✅ Quarter ${quarter} ${checkbox.checked ? 'LOCKED' : 'UNLOCKED'} successfully`);
            
            // Refresh the lock state in the current tab
            if (currentLockTabId) {
                loadQuarterLocks(currentLockTabId);
            }
        } else {
            showWarning('Failed to update quarter lock: ' + (data.message || 'Unknown error'), 'Error');
            checkbox.checked = !checkbox.checked; // Revert
        }
    })
    .catch(error => {
        console.error('Error toggling quarter lock:', error);
        showWarning('Failed to update quarter lock', 'Error');
        checkbox.checked = !checkbox.checked; // Revert
    });
}

function setAutoLockTime(quarter) {
    const timeInput = document.getElementById(`autoLockQ${quarter}`);
    const lockTime = timeInput.value;
    
    if (!lockTime) {
        showWarning('Please select a date and time for auto-lock');
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=set_auto_lock_time&quarter=${quarter}&lock_time=${lockTime}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateClearButton(quarter, 'lock');
            showWarning(`Auto-lock scheduled for Quarter ${quarter} at ${lockTime}`, 'Success');
        } else {
            showWarning('Failed to set auto-lock time: ' + (data.message || 'Unknown error'), 'Error');
        }
    })
    .catch(error => {
        console.error('Error setting auto-lock time:', error);
        showWarning('Failed to set auto-lock time', 'Error');
    });
}

function setAutoUnlockTime(quarter) {
    const timeInput = document.getElementById(`autoUnlockQ${quarter}`);
    const unlockTime = timeInput.value;
    
    if (!unlockTime) {
        showWarning('Please select a date and time for auto-unlock');
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=set_auto_unlock_time&quarter=${quarter}&unlock_time=${unlockTime}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateClearButton(quarter, 'unlock');
            showWarning(`Auto-unlock scheduled for Quarter ${quarter} at ${unlockTime}`, 'Success');
        } else {
            showWarning('Failed to set auto-unlock time: ' + (data.message || 'Unknown error'), 'Error');
        }
    })
    .catch(error => {
        console.error('Error setting auto-unlock time:', error);
        showWarning('Failed to set auto-unlock time', 'Error');
    });
}

async function clearAutoLock(quarter) {
    const timeInput = document.getElementById(`autoLockQ${quarter}`);
    
    if (!timeInput.value) {
        showWarning('No auto-lock schedule set for this quarter', 'Notice');
        return;
    }
    
    const confirmed = await showConfirm(`Are you sure you want to clear the auto-lock schedule for Quarter ${quarter}?`, 'Confirm Clear Schedule');
    
    if (!confirmed) {
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=clear_auto_lock&quarter=${quarter}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById(`autoLockQ${quarter}`).value = '';
            updateClearButton(quarter, 'lock');
            showWarning(`Auto-lock schedule cleared for Quarter ${quarter}`, 'Success');
        } else {
            showWarning('Failed to clear auto-lock schedule: ' + (data.message || 'Unknown error'), 'Error');
        }
    })
    .catch(error => {
        console.error('Error clearing auto-lock:', error);
        showWarning('Failed to clear auto-lock schedule', 'Error');
    });
}

async function clearAutoUnlock(quarter) {
    const timeInput = document.getElementById(`autoUnlockQ${quarter}`);
    
    if (!timeInput.value) {
        showWarning('No auto-unlock schedule set for this quarter', 'Notice');
        return;
    }
    
    const confirmed = await showConfirm(`Are you sure you want to clear the auto-unlock schedule for Quarter ${quarter}?`, 'Confirm Clear Schedule');
    
    if (!confirmed) {
        return;
    }
    
    if (!confirmed) {
        return;
    }
    
    fetch('grades.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `ajax=clear_auto_unlock&quarter=${quarter}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById(`autoUnlockQ${quarter}`).value = '';
            updateClearButton(quarter, 'unlock');
            showWarning(`Auto-unlock schedule cleared for Quarter ${quarter}`, 'Success');
        } else {
            showWarning('Failed to clear auto-unlock schedule: ' + (data.message || 'Unknown error'), 'Error');
        }
    })
    .catch(error => {
        console.error('Error clearing auto-unlock:', error);
        showWarning('Failed to clear auto-unlock schedule', 'Error');
    });
}

function loadQuarterLocks(tabId) {
    const schoolSelect = document.getElementById('school-select-' + tabId);
    const schoolId = schoolSelect ? schoolSelect.value : null;
    
    console.log('loadQuarterLocks called for tabId:', tabId, 'schoolId:', schoolId, 'userRole:', userRole);
    
    if (!schoolId) {
        // No school selected, enable all inputs
        document.querySelectorAll(`#${tabId} .grade-input[data-quarter]`).forEach(input => {
            input.removeAttribute('readonly');
            input.style.backgroundColor = '';
        });
        console.log('No school selected, all inputs unlocked');
        return;
    }
    
    // Admin users have full control - bypass all quarter locks
    if (userRole === 'admin') {
        document.querySelectorAll(`#${tabId} .grade-input[data-quarter]`).forEach(input => {
            input.removeAttribute('readonly');
            input.style.backgroundColor = '';
            input.title = '';
        });
        console.log('Admin user detected - quarter locks bypassed for', tabId);
        return;
    }
    
    // Check if this is a transfer student - transfer students are not affected by quarter locks
    const tabData = window.tabSubjectData?.[tabId];
    const isTransfer = tabData && (tabData.is_transfer === true || tabData.is_transfer === 1);
    
    console.log('Transfer status check:', {tabData, isTransfer});
    
    if (isTransfer) {
        // Transfer students: unlock all quarters regardless of lock settings
        document.querySelectorAll(`#${tabId} .grade-input[data-quarter]`).forEach(input => {
            input.removeAttribute('readonly');
            input.style.backgroundColor = '';
            input.title = '';
        });
        console.log('Transfer student detected - quarter locks bypassed for', tabId);
        return;
    }
    
    console.log('Fetching quarter locks from server...');
    fetch(`grades.php?ajax=get_quarter_locks&school_attended_id=${schoolId}`)
        .then(response => response.json())
        .then(data => {
            console.log('Quarter locks received:', data);
            
            if (!data.success) {
                console.error('Failed to get quarter locks:', data.error);
                // If failed, unlock all quarters for safety
                for (let q = 1; q <= 4; q++) {
                    const inputs = document.querySelectorAll(`#${tabId} .quarter-${q}-input`);
                    inputs.forEach(input => {
                        input.removeAttribute('readonly');
                        input.style.backgroundColor = '';
                        input.title = '';
                    });
                }
                return;
            }
            
            // Apply locks to inputs (regular students only)
            for (let q = 1; q <= 4; q++) {
                const isLocked = data.locks['q' + q];
                // Try both selectors to ensure we find the inputs
                let inputs = document.querySelectorAll(`#${tabId} .quarter-${q}-input`);
                if (inputs.length === 0) {
                    inputs = document.querySelectorAll(`#${tabId} input[data-quarter="${q}"]`);
                }
                
                console.log(`Quarter ${q}: locked=${isLocked}, found ${inputs.length} inputs, selector: #${tabId} .quarter-${q}-input`);
                
                if (inputs.length === 0) {
                    console.warn(`⚠️ NO INPUTS FOUND for quarter ${q}!`);
                }
                
                inputs.forEach((input, index) => {
                    console.log(`  Input ${index}:`, input.name, 'current readonly:', input.hasAttribute('readonly'), 'setting locked=', isLocked);
                    if (isLocked) {
                        input.setAttribute('readonly', 'readonly');
                        input.style.setProperty('background-color', 'var(--input-readonly-bg)', 'important');
                        input.title = 'Quarter ' + q + ' is locked by admin';
                        input.style.cursor = 'not-allowed';
                        input.style.pointerEvents = 'none';
                    } else {
                        // AGGRESSIVE UNLOCK: Remove all possible blocking attributes
                        input.removeAttribute('readonly');
                        input.removeAttribute('disabled');
                        input.removeAttribute('aria-readonly');
                        input.readOnly = false;
                        input.disabled = false;
                        
                        // Clear ALL inline styles
                        input.style.cssText = '';
                        
                        // Re-apply only necessary styles
                        input.style.cursor = 'text';
                        input.tabIndex = 0;
                        input.title = 'Quarter ' + q + ' is unlocked';
                        
                        // Remove blocking classes
                        input.classList.remove('readonly-input');
                        input.classList.remove('readonly-light');
                        
                        // Add explicit editable marker
                        input.dataset.editable = 'true';
                        
                        // Debug: check actual state after unlock
                        setTimeout(() => {
                            console.log(`    Q${q} Input final state:`, {
                                name: input.name,
                                readOnly: input.readOnly,
                                disabled: input.disabled,
                                hasReadonlyAttr: input.hasAttribute('readonly'),
                                pointerEvents: window.getComputedStyle(input).pointerEvents,
                                userSelect: window.getComputedStyle(input).userSelect,
                                cursor: window.getComputedStyle(input).cursor
                            });
                        }, 100);
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error loading quarter locks:', error);
            // On error, unlock all quarters for safety
            for (let q = 1; q <= 4; q++) {
                const inputs = document.querySelectorAll(`#${tabId} .quarter-${q}-input`);
                inputs.forEach(input => {
                    input.removeAttribute('readonly');
                    input.style.backgroundColor = '';
                    input.title = '';
                });
            }
        });
}

// Debug function: Test if unlocked inputs are truly editable
window.testUnlockedInputs = function() {
    const q2Inputs = document.querySelectorAll('.quarter-2-input');
    if (q2Inputs.length === 0) {
        console.log('No Q2 inputs found');
        return;
    }
    
    const testInput = q2Inputs[0];
    console.log('Testing first Q2 input:', testInput.name);
    console.log('  readOnly:', testInput.readOnly);
    console.log('  disabled:', testInput.disabled);
    console.log('  style.pointerEvents:', testInput.style.pointerEvents);
    console.log('  style.userSelect:', testInput.style.userSelect);
    console.log('  tabIndex:', testInput.tabIndex);
    
    // Try to focus it
    testInput.focus();
    console.log('Focused input. Try typing now.');
    
    // Try to set a value programmatically
    const oldValue = testInput.value;
    testInput.value = '99';
    console.log(`Set value from "${oldValue}" to "${testInput.value}"`);
    
    // Check if it triggers events
    testInput.dispatchEvent(new Event('input', { bubbles: true }));
    console.log('Dispatched input event');
};

console.log('Debug helper loaded. Run testUnlockedInputs() in console to test editability.');

// Cross-tab grade synchronization
function broadcastGradeChange(tabId, subjectId, quarter, value) {
    // Get student and school info for this tab
    const tabData = window.tabSubjectData?.[tabId];
    if (!tabData) return;
    
    // Normalize empty values
    const normalizedValue = value === '' || value === null || value === undefined ? '' : value;
    
    const message = {
        type: 'grade_changed',
        studentId: tabData.studentId,
        schoolAttendedId: tabData.schoolAttendedId,
        subjectId: subjectId,
        quarter: quarter,
        value: normalizedValue,
        timestamp: Date.now(),
        sourceTabId: tabId
    };
    
    // Broadcast to other tabs via localStorage
    localStorage.setItem(storagePrefix + 'gradeSync', JSON.stringify(message));
    localStorage.removeItem(storagePrefix + 'gradeSync'); // Trigger storage event
}

// Listen for grade changes from other tabs
window.addEventListener('storage', function(e) {
    if (e.key === storagePrefix + 'gradeSync' && e.newValue) {
        try {
            const message = JSON.parse(e.newValue);
            
            if (message.type === 'grade_changed') {
                // Find all tabs with the same student and school record
                const allTabs = document.querySelectorAll('.tab-content');
                
                allTabs.forEach(tabElement => {
                    const tabId = tabElement.id;
                    
                    // Skip the tab that sent the message
                    if (tabId === message.sourceTabId) return;
                    
                    // Check if this tab has the same student/school record
                    const tabData = window.tabSubjectData?.[tabId];
                    if (tabData && 
                        tabData.studentId === message.studentId && 
                        tabData.schoolAttendedId === message.schoolAttendedId) {
                        
                        // Update the grade input in this tab
                        const input = document.querySelector(`#${tabId} input[name="grades[${message.subjectId}][q${message.quarter}]"]`);
                        if (input) {
                            // Always update, even for empty values
                            const currentValue = input.value === '' || input.value === null ? '' : input.value;
                            const newValue = message.value === '' || message.value === null ? '' : message.value;
                            
                            if (currentValue !== newValue) {
                                input.value = newValue;
                                
                                // Trigger recalculation
                                const safeFunctionName = tabId.replace(/-/g, '_');
                                if (window['calculateFinalRating_' + safeFunctionName]) {
                                    window['calculateFinalRating_' + safeFunctionName](message.subjectId);
                                }
                                if (window['calculateMAPEH_' + safeFunctionName]) {
                                    window['calculateMAPEH_' + safeFunctionName]();
                                }
                                if (window['calculateGeneralAverage_' + safeFunctionName]) {
                                    window['calculateGeneralAverage_' + safeFunctionName]();
                                }
                                
                                const displayValue = newValue === '' ? '(cleared)' : newValue;
                                console.log(`✓ Grade synced in ${tabId}: Subject ${message.subjectId} Q${message.quarter} = ${displayValue}`);
                            }
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Error processing grade sync:', error);
        }
    }
});

</script>

<!-- Close Tab Confirmation Modal -->
<div class="modal fade" id="closeTabModal" tabindex="-1" style="margin-top: 80px;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Unsaved Changes</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to close this tab?</p>
                <p class="text-danger"><i class="bi bi-info-circle"></i> Any unsaved grades will be lost. This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmCloseTab">Close Tab</button>
            </div>
        </div>
    </div>
</div>

<!-- School Subjects Management Modal -->
<div class="modal fade" id="schoolSubjectsModal" tabindex="-1">
    <div class="modal-dialog modal-xl" style="margin-top: 80px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-book"></i> Manage School Subject Format</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> <strong>Global School Subjects:</strong> These are the default subject names that will be used for all regular (non-transfer) students. Configure subject names individually for each grade level.
                </div>

                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#grade-1" type="button">
                            Grade 1
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-2" type="button">
                            Grade 2
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-3" type="button">
                            Grade 3
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-4" type="button">
                            Grade 4
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-5" type="button">
                            Grade 5
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#grade-6" type="button">
                            Grade 6
                        </button>
                    </li>
                </ul>

                <div class="tab-content" style="min-height: 400px;">
                    <!-- Grade 1 Tab -->
                    <div class="tab-pane fade show active" id="grade-1">
                        <h6 class="mb-3">Subject Names for Grade 1</h6>
                        <div id="subjects-grade-1-list">
                            <p class="text-muted">Loading...</p>
                        </div>
                    </div>

                    <!-- Grade 2 Tab -->
                    <div class="tab-pane fade" id="grade-2">
                        <h6 class="mb-3">Subject Names for Grade 2</h6>
                        <div id="subjects-grade-2-list">
                            <p class="text-muted">Loading...</p>
                        </div>
                    </div>

                    <!-- Grade 3 Tab -->
                    <div class="tab-pane fade" id="grade-3">
                        <h6 class="mb-3">Subject Names for Grade 3</h6>
                        <div id="subjects-grade-3-list">
                            <p class="text-muted">Loading...</p>
                        </div>
                    </div>

                    <!-- Grade 4 Tab -->
                    <div class="tab-pane fade" id="grade-4">
                        <h6 class="mb-3">Subject Names for Grade 4</h6>
                        <div id="subjects-grade-4-list">
                            <p class="text-muted">Loading...</p>
                        </div>
                    </div>

                    <!-- Grade 5 Tab -->
                    <div class="tab-pane fade" id="grade-5">
                        <h6 class="mb-3">Subject Names for Grade 5</h6>
                        <div id="subjects-grade-5-list">
                            <p class="text-muted">Loading...</p>
                        </div>
                    </div>

                    <!-- Grade 6 Tab -->
                    <div class="tab-pane fade" id="grade-6">
                        <h6 class="mb-3">Subject Names for Grade 6</h6>
                        <div id="subjects-grade-6-list">
                            <p class="text-muted">Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveSchoolSubjects()">
                    <i class="bi bi-save"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Success Message Modal -->
<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true" data-bs-backdrop="true" style="margin-top: 80px; z-index: 1060;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="successModalLabel">
                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                    <span id="successTitle">Success</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="successMessage" class="mb-0">Successfully saved subject!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Quarter Lock Management Modal -->
<div class="modal fade" id="quarterLockModal" tabindex="-1" style="margin-top: 20px;">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="max-height: calc(100vh - 40px); display: flex; flex-direction: column;">
            <div class="modal-header" style="flex-shrink: 0; position: sticky; top: 0; z-index: 1050; background: var(--card-bg); border-bottom: 1px solid var(--border-color);">
                <h5 class="modal-title"><i class="bi bi-lock-fill"></i> Manage Quarter Locks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="overflow-y: auto; flex: 1; will-change: scroll-position; padding-bottom: 1rem;">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Global Quarter Lock:</strong> When you lock a quarter, it will be locked for <strong>ALL students</strong> in the system. This prevents grade modifications for that quarter across the entire school.
                </div>
                
                <!-- Quarter 1 -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="bi bi-1-circle"></i> Quarter 1</h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="lock-q1" onchange="toggleQuarterLock(1)">
                                <label class="form-check-label" for="lock-q1">
                                    <span id="lock-q1-label">Unlocked</span>
                                </label>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label"><i class="bi bi-lock-fill"></i> Auto-Lock Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" id="autoLockQ1" class="form-control" onchange="updateClearButton(1, 'lock')">
                                    <button class="btn btn-primary btn-sm" onclick="setAutoLockTime(1)">Save</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clearLockQ1" onclick="clearAutoLock(1)">Clear</button>
                                </div>
                                <small class="text-muted">Quarter locks automatically at this time</small>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label"><i class="bi bi-unlock-fill"></i> Auto-Unlock Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" id="autoUnlockQ1" class="form-control" onchange="updateClearButton(1, 'unlock')">
                                    <button class="btn btn-primary btn-sm" onclick="setAutoUnlockTime(1)">Save</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clearUnlockQ1" onclick="clearAutoUnlock(1)">Clear</button>
                                </div>
                                <small class="text-muted">Quarter unlocks automatically at this time</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quarter 2 -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="bi bi-2-circle"></i> Quarter 2</h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="lock-q2" onchange="toggleQuarterLock(2)">
                                <label class="form-check-label" for="lock-q2">
                                    <span id="lock-q2-label">Unlocked</span>
                                </label>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label"><i class="bi bi-lock-fill"></i> Auto-Lock Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" id="autoLockQ2" class="form-control" onchange="updateClearButton(2, 'lock')">
                                    <button class="btn btn-primary btn-sm" onclick="setAutoLockTime(2)">Save</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clearLockQ2" onclick="clearAutoLock(2)">Clear</button>
                                </div>
                                <small class="text-muted">Quarter locks automatically at this time</small>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label"><i class="bi bi-unlock-fill"></i> Auto-Unlock Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" id="autoUnlockQ2" class="form-control" onchange="updateClearButton(2, 'unlock')">
                                    <button class="btn btn-primary btn-sm" onclick="setAutoUnlockTime(2)">Save</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clearUnlockQ2" onclick="clearAutoUnlock(2)">Clear</button>
                                </div>
                                <small class="text-muted">Quarter unlocks automatically at this time</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quarter 3 -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="bi bi-3-circle"></i> Quarter 3</h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="lock-q3" onchange="toggleQuarterLock(3)">
                                <label class="form-check-label" for="lock-q3">
                                    <span id="lock-q3-label">Unlocked</span>
                                </label>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label"><i class="bi bi-lock-fill"></i> Auto-Lock Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" id="autoLockQ3" class="form-control" onchange="updateClearButton(3, 'lock')">
                                    <button class="btn btn-primary btn-sm" onclick="setAutoLockTime(3)">Save</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clearLockQ3" onclick="clearAutoLock(3)">Clear</button>
                                </div>
                                <small class="text-muted">Quarter locks automatically at this time</small>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label"><i class="bi bi-unlock-fill"></i> Auto-Unlock Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" id="autoUnlockQ3" class="form-control" onchange="updateClearButton(3, 'unlock')">
                                    <button class="btn btn-primary btn-sm" onclick="setAutoUnlockTime(3)">Save</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clearUnlockQ3" onclick="clearAutoUnlock(3)">Clear</button>
                                </div>
                                <small class="text-muted">Quarter unlocks automatically at this time</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quarter 4 -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="bi bi-4-circle"></i> Quarter 4</h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="lock-q4" onchange="toggleQuarterLock(4)">
                                <label class="form-check-label" for="lock-q4">
                                    <span id="lock-q4-label">Unlocked</span>
                                </label>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label"><i class="bi bi-lock-fill"></i> Auto-Lock Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" id="autoLockQ4" class="form-control" onchange="updateClearButton(4, 'lock')">
                                    <button class="btn btn-primary btn-sm" onclick="setAutoLockTime(4)">Save</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clearLockQ4" onclick="clearAutoLock(4)">Clear</button>
                                </div>
                                <small class="text-muted">Quarter locks automatically at this time</small>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label"><i class="bi bi-unlock-fill"></i> Auto-Unlock Time</label>
                                <div class="input-group">
                                    <input type="datetime-local" id="autoUnlockQ4" class="form-control" onchange="updateClearButton(4, 'unlock')">
                                    <button class="btn btn-primary btn-sm" onclick="setAutoUnlockTime(4)">Save</button>
                                    <button class="btn btn-outline-secondary btn-sm" id="clearUnlockQ4" onclick="clearAutoUnlock(4)">Clear</button>
                                </div>
                                <small class="text-muted">Quarter unlocks automatically at this time</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="flex-shrink: 0; position: sticky; bottom: 0; z-index: 1050; background: var(--card-bg); border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Warning Modal -->
<div class="modal fade" id="customWarningModal" tabindex="-1" aria-labelledby="customWarningModalLabel" aria-hidden="true" data-bs-backdrop="true" style="margin-top: 80px; z-index: 1060;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customWarningModalLabel">
                    <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
                    <span id="customWarningTitle">Notice</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="customWarningMessage" class="mb-0"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Confirmation Modal -->
<div class="modal fade" id="customConfirmModal" tabindex="-1" aria-labelledby="customConfirmModalLabel" aria-hidden="true" data-bs-backdrop="true" style="margin-top: 80px; z-index: 1060;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customConfirmModalLabel">
                    <i class="bi bi-question-circle-fill text-info me-2"></i>
                    <span id="customConfirmTitle">Confirm Action</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="customConfirmMessage" class="mb-0"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="customConfirmYes">Yes</button>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
</body>
</html>
