<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start(); // Start output buffering
include_once "../includes/db.php";
include_once "../includes/subject_helpers.php";

// Handle AJAX save request FIRST before any authentication checks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    // Clean any output buffers and set JSON header
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Disable all output including errors
    error_reporting(0);
    ini_set('display_errors', 0);
    
    header('Content-Type: application/json');
    
    // Debug log
    file_put_contents('C:/xampp/htdocs/sf10_system/debug_save.log', date('Y-m-d H:i:s') . " - AJAX Save Request Received\n", FILE_APPEND);
    
    // Check authentication for AJAX - allow both teacher and admin
    if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['teacher', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    
    $current_user_id = $_SESSION['user']['id'];
    
    // Simple test to verify AJAX is reaching here
    if (!isset($_POST['student_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing student_id parameter']);
        exit();
    }
    
    try {
        $student_id = intval($_POST['student_id']);
        $school_attended_id = intval($_POST['school_attended_id']);
        $subject_id = intval($_POST['subject_id']);
        $quarter = intval($_POST['quarter']);
        $grade = !empty($_POST['grade']) ? round(floatval($_POST['grade'])) : null;
        if ($grade !== null) {
            $grade = max(0, min(100, $grade));
        }
        
        // Debug log
        file_put_contents('C:/xampp/htdocs/sf10_system/debug_save.log', 
            "Student: $student_id, School: $school_attended_id, Subject: $subject_id, Quarter: $quarter, Grade: $grade\n", 
            FILE_APPEND);
        
        // Validate inputs
        if (!$student_id || !$school_attended_id || !$subject_id || !$quarter) {
            echo json_encode(['success' => false, 'message' => 'Invalid input parameters']);
            exit();
        }
        
        // Get existing grade for comparison
        $eg_query = "SELECT grade FROM grades WHERE student_id = ? AND school_attended_id = ? AND subject_id = ? AND quarter = ?";
        $eg_stmt = $conn->prepare($eg_query);
        $eg_stmt->bind_param("iiii", $student_id, $school_attended_id, $subject_id, $quarter);
        $eg_stmt->execute();
        $eg_result = $eg_stmt->get_result();
        $old_grade = null;
        if ($eg_result->num_rows > 0) {
            $old_grade = $eg_result->fetch_assoc()['grade'];
        }
        
        // Get school info first to check school year
        $si_query = "SELECT school_year FROM schools_attended WHERE id = ?";
        $si_stmt = $conn->prepare($si_query);
        $si_stmt->bind_param("i", $school_attended_id);
        $si_stmt->execute();
        $school_info = $si_stmt->get_result()->fetch_assoc();
        $school_year = $school_info['school_year'];
        
        // Check quarter lock (both global and school-year specific)
        $lock_query = "SELECT locked FROM quarter_locks 
                       WHERE quarter = ? 
                       AND (school_attended_id IS NULL OR school_attended_id = ?)
                       AND (school_year IS NULL OR school_year = ?)
                       ORDER BY school_attended_id DESC, school_year DESC
                       LIMIT 1";
        $lock_stmt = $conn->prepare($lock_query);
        $lock_stmt->bind_param("iis", $quarter, $school_attended_id, $school_year);
        $lock_stmt->execute();
        $lock_result = $lock_stmt->get_result();
        $is_locked = false;
        
        if ($lock_result && $lock_result->num_rows > 0) {
            $lock_row = $lock_result->fetch_assoc();
            $is_locked = $lock_row['locked'] == 1;
        }
        
        // Check if trying to change locked quarter
        if ($is_locked && $grade != $old_grade) {
            echo json_encode(['success' => false, 'message' => 'Quarter ' . $quarter . ' is locked']);
            exit();
        }
        
        // Get school_year_id
        $sy_result = $conn->query("SELECT id FROM school_years WHERE year = '$school_year' LIMIT 1");
        $school_year_id = ($sy_result && $sy_result->num_rows > 0) ? $sy_result->fetch_assoc()['id'] : null;
        
        // Get old grade value for history tracking
        $old_grade = null;
        $grade_id = null;
        $check_existing = $conn->prepare("SELECT id, grade FROM grades 
                                          WHERE student_id = ? AND school_attended_id = ? 
                                          AND subject_id = ? AND quarter = ?");
        $check_existing->bind_param("iiii", $student_id, $school_attended_id, $subject_id, $quarter);
        $check_existing->execute();
        $existing_result = $check_existing->get_result()->fetch_assoc();
        if ($existing_result) {
            $grade_id = $existing_result['id'];
            $old_grade = $existing_result['grade'];
        }
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both insert and update
        $save_stmt = $conn->prepare("INSERT INTO grades
            (student_id, school_attended_id, subject_id, quarter, grade, final_rating, remarks, is_general_average, teacher_id, school_year, school_year_id)
            VALUES (?, ?, ?, ?, ?, NULL, NULL, 0, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            grade = VALUES(grade),
            teacher_id = VALUES(teacher_id)");
        
        $save_stmt->bind_param("iiidisii", $student_id, $school_attended_id, $subject_id, $quarter, $grade, $current_user_id, $school_year, $school_year_id);
        $result = $save_stmt->execute();
        
        // Get the grade_id (either the new insert ID or the existing one)
        if (!$grade_id) {
            $grade_id = $conn->insert_id;
        }
        
        // Log the change to history table if grade value changed
        if ($result && ($old_grade !== $grade)) {
            $history_stmt = $conn->prepare("INSERT INTO grades_history 
                (grade_id, student_id, school_attended_id, subject_id, quarter, old_grade, new_grade, changed_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $history_stmt->bind_param("iiiidddi", $grade_id, $student_id, $school_attended_id, $subject_id, $quarter, $old_grade, $grade, $current_user_id);
            $history_stmt->execute();
        }
        
        if ($result) {
            // Recalculate final_rating and remarks for this subject
            $quarters_query = "SELECT quarter, grade FROM grades 
                              WHERE student_id = ? AND school_attended_id = ? AND subject_id = ? 
                              AND quarter BETWEEN 1 AND 4";
            $q_stmt = $conn->prepare($quarters_query);
            $q_stmt->bind_param("iii", $student_id, $school_attended_id, $subject_id);
            $q_stmt->execute();
            $quarters_result = $q_stmt->get_result();
            
            $quarter_grades = [];
            while ($qrow = $quarters_result->fetch_assoc()) {
                if ($qrow['grade'] !== null && $qrow['grade'] !== '') {
                    $quarter_grades[] = $qrow['grade'];
                }
            }
            
            // Calculate final rating ONLY when all 4 quarters are graded
            if (count($quarter_grades) === 4) {
                $final_rating = round(array_sum($quarter_grades) / 4);
                $remarks = ($final_rating >= 75) ? 'Passed' : 'Failed';
                
                // Update all quarter records with the same final_rating and remarks
                $update_final = $conn->prepare("UPDATE grades 
                                               SET final_rating = ?, remarks = ? 
                                               WHERE student_id = ? AND school_attended_id = ? AND subject_id = ?");
                $update_final->bind_param("dsiii", $final_rating, $remarks, $student_id, $school_attended_id, $subject_id);
                $update_final->execute();
            } else {
                // Not all 4 quarters are present — clear final_rating and remarks
                $clear_final = $conn->prepare("UPDATE grades 
                                              SET final_rating = NULL, remarks = NULL 
                                              WHERE student_id = ? AND school_attended_id = ? AND subject_id = ?");
                $clear_final->bind_param("iii", $student_id, $school_attended_id, $subject_id);
                $clear_final->execute();
            }
            
            echo json_encode(['success' => true, 'message' => 'Saved']);
        } else {
            $error = $existing ? $update_stmt->error : $insert_stmt->error;
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $error]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Normal page load - Role check: Allow both teachers and admins
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['teacher', 'admin'])) {
    header("Location: ../login.php");
    exit();
}

$current_user_id = $_SESSION['user']['id'];
$grade_level = isset($_GET['grade_level']) ? intval($_GET['grade_level']) : 0;
$section = isset($_GET['section']) ? $_GET['section'] : '';

if (!$grade_level || !$section) {
    $_SESSION['error_message'] = "Invalid parameters";
    header("Location: input_grades.php");
    exit();
}

// Get current school year from session (selected year), fallback to globally active year
$active_school_year = null;
$active_school_year_id = null;

if (isset($_SESSION['school_year']) && isset($_SESSION['school_year_id'])) {
    $active_school_year = $_SESSION['school_year'];
    $active_school_year_id = $_SESSION['school_year_id'];
} else {
    $active_sy_query = "SELECT id, year FROM school_years WHERE is_active = 1 LIMIT 1";
    $active_sy_result = $conn->query($active_sy_query);
    if ($active_sy_result && $active_sy_result->num_rows > 0) {
        $sy_row = $active_sy_result->fetch_assoc();
        $active_school_year = $sy_row['year'];
        $active_school_year_id = $sy_row['id'];
    }
}

// Get teacher's subject assignments for this class - FILTERED BY SCHOOL YEAR
$assignment_query = "SELECT subject_id FROM teacher_assignments 
                    WHERE teacher_id = ? 
                    AND assignment_type = 'subject'
                    AND grade_level = ?
                    AND LOWER(section) = LOWER(?)
                    AND school_year = ?";
$as_stmt = $conn->prepare($assignment_query);
$as_stmt->bind_param("iiss", $current_user_id, $grade_level, $section, $active_school_year);
$as_stmt->execute();
$assignment_result = $as_stmt->get_result();

$assigned_subject_ids = [];
while ($row = $assignment_result->fetch_assoc()) {
    $assigned_subject_ids[] = $row['subject_id'];
}

// If teacher is assigned to MAPEH (subject_id = 8), automatically include component subjects
// and exclude MAPEH itself from the tabs. Only include components that have a configured
// (non-empty) display name in subject_grade_groups for this grade level.
$has_mapeh_assignment = in_array(8, $assigned_subject_ids);
if ($has_mapeh_assignment) {
    // Remove MAPEH from the list
    $assigned_subject_ids = array_diff($assigned_subject_ids, [8]);

    // Fetch which MAPEH components (9,10,11,12) have a non-empty name configured for this grade and school year
    $comp_check = $conn->prepare(
        "SELECT subject_id FROM subject_grade_groups
         WHERE subject_id IN (9,10,11,12)
         AND grade_level = ?
         AND (school_year = ? OR school_year IS NULL)
         AND subject_name != ''"
    );
    $comp_check->bind_param("is", $grade_level, $active_school_year);
    $comp_check->execute();
    $comp_result = $comp_check->get_result();
    $configured_components = [];
    while ($cr = $comp_result->fetch_assoc()) {
        $configured_components[] = (int)$cr['subject_id'];
    }
    $comp_check->close();

    // Only add components that are configured (have a non-empty display name)
    foreach ($configured_components as $component_id) {
        if (!in_array($component_id, $assigned_subject_ids)) {
            $assigned_subject_ids[] = $component_id;
        }
    }
}

if (empty($assigned_subject_ids)) {
    echo "You have no subject assignments for this class";
    exit();
}

if (empty($assigned_subject_ids)) {
    $_SESSION['error_message'] = "You have no subject assignments for this class";
    header("Location: input_grades.php");
    exit();
}

// Get subjects for this grade level (only those assigned to teacher)
$subjects = getSubjectsByGrade($conn, $grade_level, true, $active_school_year);

// Filter to only show teacher's assigned subjects
$subjects = array_filter($subjects, function($subject) use ($assigned_subject_ids) {
    return in_array($subject['id'], $assigned_subject_ids);
});

// Re-index array after filtering
$subjects = array_values($subjects);

// Custom sort to group MAPEH components together
usort($subjects, function($a, $b) {
    // Priority: Group MAPEH (8) and components (9-12) together
    $mapeh_ids = [8, 9, 10, 11, 12];
    $a_id = (int)$a['id'];
    $b_id = (int)$b['id'];
    
    $a_is_mapeh = in_array($a_id, $mapeh_ids);
    $b_is_mapeh = in_array($b_id, $mapeh_ids);
    
    if ($a_is_mapeh && $b_is_mapeh) {
        // Both are MAPEH related, sort by ID (usually 8, 9, 10, 11, 12)
        return $a_id <=> $b_id;
    }
    
    if ($a_is_mapeh) {
        // Only a is MAPEH, use a virtual display order of 8 to group it
        $a_order = 8;
        $b_order = (int)$b['display_order'];
        if ($a_order != $b_order) return $a_order <=> $b_order;
        return strcmp($a['subject_name'], $b['subject_name']);
    }
    
    if ($b_is_mapeh) {
        // Only b is MAPEH
        $a_order = (int)$a['display_order'];
        $b_order = 8;
        if ($a_order != $b_order) return $a_order <=> $b_order;
        return strcmp($a['subject_name'], $b['subject_name']);
    }
    
    // Neither is MAPEH, use standard sort
    if ($a['display_order'] != $b['display_order']) {
        return $a['display_order'] <=> $b['display_order'];
    }
    return strcmp($a['subject_name'], $b['subject_name']);
});

if (empty($subjects)) {
    $_SESSION['error_message'] = "No subjects found for grade level $grade_level";
    header("Location: input_grades.php");
    exit();
}

// Get all students in this class
$students_query = "SELECT s.id, s.first_name, s.last_name, s.lrn, s.gender, sa.id as school_attended_id,
                  sa.is_transfer, sa.transfer_quarter
                  FROM students s
                  INNER JOIN schools_attended sa ON s.id = sa.student_id
                  WHERE sa.grade_level = ?
                  AND LOWER(sa.section) = LOWER(?)
                  AND sa.school_year = ?
                  ORDER BY s.last_name, s.first_name";

$st_stmt = $conn->prepare($students_query);
$st_stmt->bind_param("iss", $grade_level, $section, $active_school_year);
$st_stmt->execute();
$students_result = $st_stmt->get_result();

// Fetch students into array
$students = [];
while ($row = $students_result->fetch_assoc()) {
    $students[] = $row;
}

// Get custom subject names for all students in this class
$custom_subject_names = [];
if (!empty($students)) {
    $student_ids = array_column($students, 'id');
    $school_attended_ids = array_column($students, 'school_attended_id');
    $placeholders_students = str_repeat('?,', count($student_ids) - 1) . '?';
    $placeholders_sa = str_repeat('?,', count($school_attended_ids) - 1) . '?';

    $custom_query = "SELECT student_id, subject_id, custom_subject_name 
                     FROM student_custom_subjects 
                     WHERE student_id IN ($placeholders_students) 
                     AND school_attended_id IN ($placeholders_sa)
                     AND subject_id = ?";
    
    $cs_stmt = $conn->prepare($custom_query);
    $params = array_merge($student_ids, $school_attended_ids, [$active_subject_id]);
    $types = str_repeat('i', count($params));
    $cs_stmt->bind_param($types, ...$params);
    $cs_stmt->execute();
    $cs_result = $cs_stmt->get_result();
    
    while ($cs_row = $cs_result->fetch_assoc()) {
        $custom_subject_names[$cs_row['student_id']] = $cs_row['custom_subject_name'];
    }
}

// Get all existing grades for these students and subjects
$all_grades = [];
if (!empty($students)) {
    $student_ids = array_column($students, 'id');
    $subject_ids = array_column($subjects, 'id');
    $school_attended_ids = array_column($students, 'school_attended_id');

    $placeholders_students = str_repeat('?,', count($student_ids) - 1) . '?';
    $placeholders_subjects = str_repeat('?,', count($subject_ids) - 1) . '?';
    $placeholders_sa = str_repeat('?,', count($school_attended_ids) - 1) . '?';

    $grades_query = "SELECT student_id, subject_id, quarter, grade 
                     FROM grades 
                     WHERE student_id IN ($placeholders_students) 
                     AND subject_id IN ($placeholders_subjects)
                     AND school_attended_id IN ($placeholders_sa)";

    $g_stmt = $conn->prepare($grades_query);
    $params = array_merge($student_ids, $subject_ids, $school_attended_ids);
    $types = str_repeat('i', count($params));
    $g_stmt->bind_param($types, ...$params);
    $g_stmt->execute();
    $grades_result = $g_stmt->get_result();

    while ($grade_row = $grades_result->fetch_assoc()) {
        $key = $grade_row['student_id'] . '_' . $grade_row['subject_id'] . '_' . $grade_row['quarter'];
        $all_grades[$key] = $grade_row['grade'];
    }
}

// Get quarter locks for all students (filtered by school year)
$all_locks = [];
if (!empty($students)) {
    $school_attended_ids = array_column($students, 'school_attended_id');
    $placeholders = str_repeat('?,', count($school_attended_ids) - 1) . '?';
    
    // Get locks for specific students or global locks for this school year
    $locks_query = "SELECT school_attended_id, quarter, locked 
                    FROM quarter_locks 
                    WHERE (school_attended_id IN ($placeholders) OR school_attended_id IS NULL)
                    AND (school_year = ? OR school_year IS NULL)
                    ORDER BY school_attended_id DESC, school_year DESC";
    
    $l_stmt = $conn->prepare($locks_query);
    $params = array_merge($school_attended_ids, [$active_school_year]);
    $types = str_repeat('i', count($school_attended_ids)) . 's';
    $l_stmt->bind_param($types, ...$params);
    $l_stmt->execute();
    $locks_result = $l_stmt->get_result();
    
    $processed_keys = [];
    while ($lock_row = $locks_result->fetch_assoc()) {
        $sa_id = $lock_row['school_attended_id'] ?? 'global';
        $quarter = $lock_row['quarter'];
        $key = $sa_id . '_' . $quarter;
        
        // Only process each school_attended_id + quarter combination once (prioritize specific locks)
        if (isset($processed_keys[$key])) continue;
        $processed_keys[$key] = true;
        
        // Apply global locks to all students if no specific lock exists
        if ($sa_id === 'global') {
            foreach ($school_attended_ids as $student_sa_id) {
                $student_key = $student_sa_id . '_' . $quarter;
                if (!isset($processed_keys[$student_key])) {
                    if (!isset($all_locks[$student_sa_id])) {
                        $all_locks[$student_sa_id] = [
                            'q1_locked' => 0,
                            'q2_locked' => 0,
                            'q3_locked' => 0,
                            'q4_locked' => 0
                        ];
                    }
                    $all_locks[$student_sa_id]['q' . $quarter . '_locked'] = $lock_row['locked'];
                }
            }
        } else {
            if (!isset($all_locks[$sa_id])) {
                $all_locks[$sa_id] = [
                    'q1_locked' => 0,
                    'q2_locked' => 0,
                    'q3_locked' => 0,
                    'q4_locked' => 0
                ];
            }
            $all_locks[$sa_id]['q' . $quarter . '_locked'] = $lock_row['locked'];
        }
    }
}

    // Get Global Locks for Headers (for display in table header)
    $global_locks = [1 => false, 2 => false, 3 => false, 4 => false];
    $gl_query = "SELECT quarter, locked FROM quarter_locks WHERE school_attended_id IS NULL AND school_year = ?";
    $gl_stmt = $conn->prepare($gl_query);
    $gl_stmt->bind_param("s", $active_school_year);
    $gl_stmt->execute();
    $gl_result = $gl_stmt->get_result();
    while ($row = $gl_result->fetch_assoc()) {
        $global_locks[$row['quarter']] = $row['locked'] == 1;
    }

include_once "../templates/header.php";

// Get active subject (default to first subject)
$active_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : (count($subjects) > 0 ? $subjects[0]['id'] : 0);
$target_student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$active_subject = null;
foreach ($subjects as $subj) {
    if ($subj['id'] == $active_subject_id) {
        $active_subject = $subj;
        break;
    }
}
if (!$active_subject && count($subjects) > 0) {
    $active_subject = $subjects[0];
    $active_subject_id = $active_subject['id'];
}
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-pencil-square"></i> Input Grades</h2>
        <p class="subtitle mb-0">Grade <?php echo $grade_level; ?> - <?php echo htmlspecialchars($section); ?> | SY <?php echo htmlspecialchars($active_school_year); ?></p>
    </div>
    <a href="input_grades.php" class="btn btn-info">
        <i class="bi bi-arrow-left"></i> Back to Selection
    </a>
</div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo htmlspecialchars($_SESSION['error_message']); 
            unset($_SESSION['error_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Auto-save status indicator -->
    <div id="saveStatus" class="alert alert-info mb-3" style="display: none;">
        <i class="bi bi-clock-history"></i> <span id="saveStatusText">Saving...</span>
    </div>

    <!-- Subject Tabs -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center flex-wrap gap-2">
            <span class="me-2"><i class="bi bi-journal-text"></i> Subjects</span>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($subjects as $subject): ?>
                    <a class="btn btn-sm <?php echo ($subject['id'] == $active_subject_id) ? 'btn-info text-dark fw-semibold' : 'btn-outline-secondary'; ?>"
                       href="?grade_level=<?php echo $grade_level; ?>&section=<?php echo urlencode($section); ?>&subject_id=<?php echo $subject['id']; ?>">
                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Grade Input Table -->
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table"></i> Grade Sheet</span>
            <span class="badge bg-secondary"><?php echo count($students); ?> student<?php echo count($students) != 1 ? 's' : ''; ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="align-middle">Name</th>
                            <th class="align-middle">Gender</th>
                            <th class="text-center align-middle">
                                Q1
                                <?php if ($global_locks[1]): ?>
                                    <i class="bi bi-lock-fill lock-icon" title="Quarter 1 Locked"></i>
                                <?php endif; ?>
                            </th>
                            <th class="text-center align-middle">
                                Q2
                                <?php if ($global_locks[2]): ?>
                                    <i class="bi bi-lock-fill lock-icon" title="Quarter 2 Locked"></i>
                                <?php endif; ?>
                            </th>
                            <th class="text-center align-middle">
                                Q3
                                <?php if ($global_locks[3]): ?>
                                    <i class="bi bi-lock-fill lock-icon" title="Quarter 3 Locked"></i>
                                <?php endif; ?>
                            </th>
                            <th class="text-center align-middle">
                                Q4
                                <?php if ($global_locks[4]): ?>
                                    <i class="bi bi-lock-fill lock-icon" title="Quarter 4 Locked"></i>
                                <?php endif; ?>
                            </th>
                            <th class="text-center align-middle">Average</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): 
                            $locks = isset($all_locks[$student['school_attended_id']]) ? $all_locks[$student['school_attended_id']] : ['q1_locked' => 0, 'q2_locked' => 0, 'q3_locked' => 0, 'q4_locked' => 0];
                            $is_target = ($target_student_id && $student['id'] == $target_student_id);
                        ?>
                            <tr id="student-row-<?= $student['id'] ?>" <?= $is_target ? 'class="table-primary target-student"' : '' ?>>
                                <td>
                                    <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                    <?php if (!empty($student['is_transfer'])): 
                                        $display_name = isset($custom_subject_names[$student['id']]) 
                                            ? $custom_subject_names[$student['id']] 
                                            : $active_subject['subject_name'];
                                    ?>
                                        <br><small class="text-muted"><i>Subject: <?= htmlspecialchars($display_name) ?></i></small>
                                        <span class="badge bg-danger ms-1" style="font-size:0.75em; padding: 4px 8px;" title="Transferee<?= !empty($student['transfer_quarter']) ? ' - Q' . $student['transfer_quarter'] : '' ?>">
                                            <i class="bi bi-arrow-left-right"></i> TRANSFEREE<?= !empty($student['transfer_quarter']) ? ' - Q' . $student['transfer_quarter'] : '' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($student['gender']); ?></td>
                                <?php 
                                $total_grades = 0;
                                $grade_count = 0;
                                for ($q = 1; $q <= 4; $q++):
                                    $key = $student['id'] . '_' . $active_subject_id . '_' . $q;
                                    $grade = isset($all_grades[$key]) ? $all_grades[$key] : '';
                                    $is_locked = $locks['q' . $q . '_locked'] == 1;
                                    
                                    if ($grade !== '' && $grade !== null) {
                                        $total_grades += floatval($grade);
                                        $grade_count++;
                                    }
                                ?>
                                    <td class="p-1 align-middle text-center">
                                        <input 
                                            type="number" 
                                            class="form-control form-control-sm text-center grade-input mx-auto <?php echo $is_locked ? 'bg-light' : ''; ?>"
                                            value="<?php echo htmlspecialchars($grade); ?>"
                                            min="0"
                                            max="100"
                                            step="1"
                                            data-student-id="<?php echo $student['id']; ?>"
                                            data-school-attended-id="<?php echo $student['school_attended_id']; ?>"
                                            data-subject-id="<?php echo $active_subject_id; ?>"
                                            data-quarter="<?php echo $q; ?>"
                                            data-locked="<?php echo $is_locked ? '1' : '0'; ?>"
                                            <?php echo $is_locked ? 'readonly' : ''; ?>
                                            <?php if ($is_locked): ?>
                                                title="Q<?php echo $q; ?> is locked"
                                            <?php endif; ?>
                                        >
                                    </td>
                                <?php endfor; 
                                $average = $grade_count > 0 ? round($total_grades / $grade_count) : '';
                                ?>
                                <td class="text-center align-middle fw-bold"><?php echo $average; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Form Actions (same style as add_class.php) -->
    <div class="d-flex justify-content-end gap-2 mt-4">
        <a href="input_grades.php" class="btn btn-secondary">
            <i class="bi bi-x-circle"></i> Cancel
        </a>
        <button type="button" id="saveAllBtn" class="btn btn-primary">
            <i class="bi bi-save"></i> Save All Grades
        </button>
    </div>


<script>
let modifiedInputs = new Set();

document.addEventListener('DOMContentLoaded', function() {
    const gradeInputs = document.querySelectorAll('.grade-input');
    const saveBtn = document.getElementById('saveAllBtn');
    
    // Track which inputs have been modified
    gradeInputs.forEach(input => {
        input.addEventListener('input', function() {
            if (this.dataset.locked === '1') return;
            
            // Prevent input over 100
            let val = parseFloat(this.value);
            if (val > 100) {
                this.value = 100;
            } else if (val < 0) {
                this.value = 0;
            }
            
            modifiedInputs.add(this);
            // Recalculate average immediately
            recalculateAverage(this.closest('tr'));
        });

        // Press Enter to save all grades
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveAllGrades();
            }
        });
    });
    
    // Save all modified grades when button is clicked
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            saveAllGrades();
        });
    }
});

function saveAllGrades() {
    if (modifiedInputs.size === 0) {
        alert('No changes to save');
        return;
    }
    
    console.log('Saving', modifiedInputs.size, 'grades...');
    document.getElementById('saveStatus').style.display = 'block';
    document.getElementById('saveStatusText').textContent = 'Saving ' + modifiedInputs.size + ' grade(s)...';
    
    const promises = [];
    modifiedInputs.forEach(input => {
        console.log('Saving grade for student', input.dataset.studentId, 'Q' + input.dataset.quarter, '=', input.value);
        promises.push(saveGrade(input));
    });
    
    Promise.all(promises).then(() => {
        console.log('All grades saved successfully');
        modifiedInputs.clear();
        document.getElementById('saveStatusText').textContent = '✓ All grades saved successfully';
        document.getElementById('saveStatus').classList.remove('alert-info');
        document.getElementById('saveStatus').classList.add('alert-success');
        
        setTimeout(() => {
            document.getElementById('saveStatus').style.display = 'none';
            document.getElementById('saveStatus').classList.remove('alert-success');
            document.getElementById('saveStatus').classList.add('alert-info');
        }, 2000);
    }).catch(error => {
        console.error('Error saving grades:', error);
        document.getElementById('saveStatusText').textContent = '✗ Error saving some grades';
        document.getElementById('saveStatus').classList.remove('alert-info');
        document.getElementById('saveStatus').classList.add('alert-danger');
    });
}

function saveGrade(inputElement) {
    const data = new FormData();
    data.append('ajax_save', '1');
    data.append('student_id', inputElement.dataset.studentId);
    data.append('school_attended_id', inputElement.dataset.schoolAttendedId);
    data.append('subject_id', inputElement.dataset.subjectId);
    data.append('quarter', inputElement.dataset.quarter);
    data.append('grade', inputElement.value);
    
    console.log('[SAVE] Sending AJAX request:', {
        student_id: inputElement.dataset.studentId,
        school_attended_id: inputElement.dataset.schoolAttendedId,
        subject_id: inputElement.dataset.subjectId,
        quarter: inputElement.dataset.quarter,
        grade: inputElement.value
    });
    
    console.log('[SAVE] Fetch URL:', window.location.href);
    
    return fetch('input_grades_form.php', {
        method: 'POST',
        body: data,
        cache: 'no-cache'
    })
    .then(response => {
        console.log('[SAVE] Response received - Status:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('[SAVE] Raw response text:', text);
        try {
            const result = JSON.parse(text);
            console.log('[SAVE] Parsed JSON:', result);
            if (!result.success) {
                throw new Error(result.message || 'Save failed');
            }
            return result;
        } catch (e) {
            console.error('[SAVE] JSON parse error:', e);
            console.error('[SAVE] Response was:', text);
            throw new Error('Invalid server response');
        }
    })
    .catch(error => {
        console.error('[SAVE] Save error:', error);
        throw error;
    });
}

function recalculateAverage(row) {
    const gradeInputs = row.querySelectorAll('.grade-input');
    let total = 0;
    let count = 0;
    
    gradeInputs.forEach(input => {
        const value = parseFloat(input.value);
        if (!isNaN(value) && value !== '') {
            total += value;
            count++;
        }
    });
    
    const averageCell = row.querySelector('td:last-child');
    if (count > 0) {
        averageCell.textContent = Math.round(total / count);
    } else {
        averageCell.textContent = '';
    }
}
</script>

<style>
.table thead.sticky-top {
    position: sticky;
    top: 0;
    z-index: 10;
    background-color: var(--card-bg, #f8f9fa);
}

.grade-input {
    width: 70px;
    padding: 0.25rem 0.5rem;
}

/* Remove number input arrows/spinners */
.grade-input::-webkit-inner-spin-button,
.grade-input::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.grade-input[type=number] {
    -moz-appearance: textfield;
}

.grade-input[readonly] {
    cursor: not-allowed;
    background-color: #e9ecef !important; /* Grey background for light mode */
}

/* Dark mode adjustment for readonly input */
[data-bs-theme="dark"] .grade-input[readonly],
body.dark-theme .grade-input[readonly] {
    background-color: #343a40 !important; /* Darker grey for dark mode */
    color: #adb5bd;
    border-color: #495057;
}

.grade-input:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.table td {
    vertical-align: middle;
}

.lock-icon {
    font-size: 1.2em;
    margin-left: 4px;
    color: #000000;
}

[data-bs-theme="dark"] .lock-icon {
    color: #ffffff !important;
}

body.dark-theme .lock-icon {
    color: #ffffff !important;
}
</style>

<?php if ($target_student_id): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var row = document.getElementById('student-row-<?= $target_student_id ?>');
    if (row) {
        setTimeout(function() {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 300);
    }
});
</script>
<?php endif; ?>

<?php 
ob_end_flush(); // Flush the output buffer
include_once "../templates/footer.php"; 
?>
