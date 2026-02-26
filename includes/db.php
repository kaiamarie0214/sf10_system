<?php
$servername = "localhost";
$username = "root";
$password = "kaia0214";
$database = "sf10_system";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
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