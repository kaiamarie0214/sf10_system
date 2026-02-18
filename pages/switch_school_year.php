<?php
session_start();
include "../includes/db.php";

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only admin can switch school years.']);
    exit();
}

// Check if school_year_id is provided
if (!isset($_POST['school_year_id']) || empty($_POST['school_year_id'])) {
    echo json_encode(['success' => false, 'message' => 'School year ID is required.']);
    exit();
}

$school_year_id = (int)$_POST['school_year_id'];

// Validate that the school year exists
$sy_query = $conn->prepare("SELECT id, year, status FROM school_years WHERE id = ?");
$sy_query->bind_param("i", $school_year_id);
$sy_query->execute();
$result = $sy_query->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school year ID.']);
    exit();
}

$school_year = $result->fetch_assoc();

// Update session with new school year
$_SESSION['school_year_id'] = $school_year['id'];
$_SESSION['school_year'] = $school_year['year'];
$_SESSION['school_year_status'] = $school_year['status'];

// Log the school year switch
include "../includes/logger.php";
logActivity($conn, $_SESSION['user']['id'], 'SWITCH_SCHOOL_YEAR', 'school_years', $school_year['id'], 
           "Admin switched to school year: {$school_year['year']}");

echo json_encode([
    'success' => true, 
    'message' => 'Successfully switched to school year ' . $school_year['year'],
    'school_year' => $school_year['year'],
    'school_year_id' => $school_year['id']
]);
?>
