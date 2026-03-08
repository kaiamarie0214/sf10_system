<?php
$servername = "localhost";
$username = "root";
$password = "kaia0214";
$database = "sf10_system";

// Set timezone to Manila
date_default_timezone_set('Asia/Manila');

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Migration: Add display_order to schools_attended if not exists
$check_col = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'display_order'");
if ($check_col && $check_col->num_rows === 0) {
    $conn->query("ALTER TABLE schools_attended ADD COLUMN display_order INT DEFAULT 0 AFTER is_transfer");
}

// Migration: Add school_year to subject_grade_groups if not exists
$check_sy_col = $conn->query("SHOW COLUMNS FROM subject_grade_groups LIKE 'school_year'");
if ($check_sy_col && $check_sy_col->num_rows === 0) {
    // Add column
    $conn->query("ALTER TABLE subject_grade_groups ADD COLUMN school_year VARCHAR(15) DEFAULT NULL AFTER subject_name");
    
    // Update existing records to the current active school year as a starting point
    $active_sy_res = $conn->query("SELECT year FROM school_years WHERE is_active = 1 LIMIT 1");
    if ($active_sy_res && $active_sy_res->num_rows > 0) {
        $active_year = $active_sy_res->fetch_assoc()['year'];
        $conn->query("UPDATE subject_grade_groups SET school_year = '$active_year' WHERE school_year IS NULL");
    }
}

// Robust cleanup of old unique indexes that cause sharing across years
$idx_res = $conn->query("SHOW INDEX FROM subject_grade_groups");
$old_indexes = [];
if ($idx_res) {
    while ($row = $idx_res->fetch_assoc()) {
        $key_name = $row['Key_name'];
        if ($key_name === 'PRIMARY') continue;
        if (!isset($old_indexes[$key_name])) $old_indexes[$key_name] = [];
        $old_indexes[$key_name][] = $row['Column_name'];
    }
    
    foreach ($old_indexes as $key_name => $columns) {
        // If index only has subject_id and grade_level, it's an old one that needs to be removed
        if (count($columns) === 2 && in_array('subject_id', $columns) && in_array('grade_level', $columns)) {
            $conn->query("ALTER TABLE subject_grade_groups DROP INDEX `$key_name` ");
        }
    }
}

// Add the new unique key that strictly includes school_year
$check_index = $conn->query("SHOW INDEX FROM subject_grade_groups WHERE Key_name = 'subject_grade_year'");
if ($check_index && $check_index->num_rows === 0) {
    $conn->query("ALTER TABLE subject_grade_groups ADD UNIQUE KEY `subject_grade_year` (`subject_id`, `grade_level`, `school_year`) ");
}

/**
 * Checks if a teacher has access to a specific student
 * @param mysqli $conn Database connection
 * @param int $teacher_id Teacher's user ID
 * @param int $student_id Student's ID
 * @param int|null $school_year_id School year ID (defaults to session)
 * @return bool True if teacher is admin or assigned to the student's class/subject
 */
function has_teacher_access_to_student($conn, $teacher_id, $student_id, $school_year_id = null) {
    if (!isset($_SESSION)) { session_start(); }
    if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin') {
        return true;
    }

    if ($school_year_id === null) {
        $school_year_id = $_SESSION['school_year_id'] ?? 0;
    }

    $query = "
        SELECT COUNT(*) as count 
        FROM schools_attended sa
        JOIN teacher_assignments ta ON (
            (ta.assignment_type = 'adviser' AND sa.grade_level = ta.grade_level AND sa.section = ta.section)
            OR 
            (ta.assignment_type = 'subject' AND sa.grade_level = ta.grade_level AND sa.section = ta.section)
        )
        WHERE sa.student_id = ? 
        AND ta.teacher_id = ? 
        AND ta.school_year_id = ?
        AND sa.school_year = (SELECT year FROM school_years WHERE id = ?)
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiii", $student_id, $teacher_id, $school_year_id, $school_year_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['count'] > 0;
}
?>