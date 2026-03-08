<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/logger.php";

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit();
}

$user = $_SESSION['user'];
$is_admin = $user['role'] === 'admin';

// AJAX endpoint for reordering school records
if (isset($_GET['ajax']) && $_GET['ajax'] === 'reorder_school' && $is_admin) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $record_id = (int)$input['id'];
    $direction = $input['direction']; // 'up' or 'down'

    // Get current record info
    $stmt = $conn->prepare("SELECT student_id, grade_level, school_year, display_order FROM schools_attended WHERE id = ?");
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();

    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
        exit;
    }

    $student_id = $current['student_id'];
    $grade_level = $current['grade_level'];
    $current_order = $current['display_order'];

    // Fetch all records for this student and grade level, sorted exactly as they appear in the UI
    $list_stmt = $conn->prepare("SELECT id, display_order FROM schools_attended 
                                WHERE student_id = ? AND grade_level = ? 
                                ORDER BY display_order ASC, school_year ASC, id ASC");
    $list_stmt->bind_param("is", $student_id, $grade_level);
    $list_stmt->execute();
    $records = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $swap_target = null;
    $found_idx = -1;

    // Find the current record index in the list
    foreach ($records as $idx => $rec) {
        if ($rec['id'] == $record_id) {
            $found_idx = $idx;
            break;
        }
    }

    // Determine the swap target (the neighbor in the list)
    if ($found_idx !== -1) {
        if ($direction === 'up' && $found_idx > 0) {
            $swap_target = $records[$found_idx - 1];
        } else if ($direction === 'down' && $found_idx < count($records) - 1) {
            $swap_target = $records[$found_idx + 1];
        }
    }

    if ($swap_target) {
        $target_id = $swap_target['id'];
        $target_order = $swap_target['display_order'];

        // If they have the same order value, assign distinct sequential orders
        // This ensures the next swap works based on order differences
        if ($target_order == $current_order) {
            if ($direction === 'up') {
                $target_order = $current_order - 1;
            } else {
                $target_order = $current_order + 1;
            }
        }

        $conn->begin_transaction();
        try {
            $update1 = $conn->prepare("UPDATE schools_attended SET display_order = ? WHERE id = ?");
            $update1->bind_param("ii", $target_order, $record_id);
            $update1->execute();

            $update2 = $conn->prepare("UPDATE schools_attended SET display_order = ? WHERE id = ?");
            $update2->bind_param("ii", $current_order, $target_id);
            $update2->execute();

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No record to swap with']);
    }
    exit;
}

// Handle Add School Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add' && $is_admin) {
    $student_id = (int)$_POST['student_id'];
    $grade_level = (int)$_POST['grade_level'];
    $school_year = trim($_POST['school_year']);
    
    // Validate school year format (YYYY-YYYY)
    if (!preg_match('/^\d{4}-\d{4}$/', $school_year)) {
        $_SESSION['error_message'] = "Invalid school year format. Use YYYY-YYYY";
    } else {
        // Automatically detect if this is an internal school record (regular)
        // by checking if the adviser name exists in our users table
        $adviser_name = trim($_POST['adviser_name']);
        $is_internal = 0;
        if (!empty($adviser_name)) {
            $check_user = $conn->prepare("SELECT id FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
            $check_user->bind_param("s", $adviser_name);
            $check_user->execute();
            if ($check_user->get_result()->num_rows > 0) {
                $is_internal = 1;
            }
        }
        $is_transfer = ($is_internal === 1) ? 0 : 1;

        // ALLOW multiple records per year for mid-year transfers
        // Insert new school record
        $stmt = $conn->prepare("INSERT INTO schools_attended 
            (student_id, school_name, school_id, district, division, region, grade_level, section, school_year, adviser_name, is_transfer)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("isssssisssi",
                $student_id,
                $_POST['school_name'],
                $_POST['school_id'],
                $_POST['district'],
                $_POST['division'],
                $_POST['region'],
                $grade_level,
                $_POST['section'],
                $school_year,
                $adviser_name,
                $is_transfer
            );
            
            if ($stmt->execute()) {
                // Get student info for detailed log
                $student_query = $conn->prepare("SELECT first_name, last_name, lrn FROM students WHERE id = ?");
                $student_query->bind_param("i", $student_id);
                $student_query->execute();
                $student_info = $student_query->get_result()->fetch_assoc();
                $student_name = $student_info['first_name'] . ' ' . $student_info['last_name'];
                
                logActivity($conn, $user['id'], 'INSERT', 'schools_attended', $conn->insert_id, 
                           "Added Grade {$grade_level} record for $student_name (LRN: {$student_info['lrn']}) at {$_POST['school_name']} - Section {$_POST['section']} (TRANSFER)");
                
                $_SESSION['success_message'] = "School record added successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to save school record";
            }
        }
        
        header("Location: grade_progression.php?student_id=$student_id");
        exit();
    }

// Handle Edit School Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit' && $is_admin) {
    $record_id = (int)$_POST['record_id'];
    $student_id = (int)$_POST['student_id'];
    $grade_level = (int)$_POST['grade_level'];
    $school_year = trim($_POST['school_year']);
    
    // Validate school year format (YYYY-YYYY)
    if (!preg_match('/^\d{4}-\d{4}$/', $school_year)) {
        $_SESSION['error_message'] = "Invalid school year format. Use YYYY-YYYY";
    } else {
        // Automatically detect if this is an internal school record (regular)
        // by checking if the adviser name exists in our users table
        $adviser_name = trim($_POST['adviser_name']);
        $is_internal = 0;
        if (!empty($adviser_name)) {
            $check_user = $conn->prepare("SELECT id FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
            $check_user->bind_param("s", $adviser_name);
            $check_user->execute();
            if ($check_user->get_result()->num_rows > 0) {
                $is_internal = 1;
            }
        }
        $is_transfer = ($is_internal === 1) ? 0 : 1;

        // ALLOW multiple records per year for mid-year transfers
        $stmt = $conn->prepare("UPDATE schools_attended SET 
            school_name = ?, school_id = ?, district = ?, division = ?, region = ?,
            section = ?, school_year = ?, adviser_name = ?, is_transfer = ?
            WHERE id = ?");
            
            $stmt->bind_param("ssssssssii",
                $_POST['school_name'],
                $_POST['school_id'],
                $_POST['district'],
                $_POST['division'],
                $_POST['region'],
                $_POST['section'],
                $school_year,
                $adviser_name,
                $is_transfer,
                $record_id
            );
            
            if ($stmt->execute()) {
                // Get student info for detailed log
                $student_query = $conn->prepare("SELECT first_name, last_name, lrn FROM students WHERE id = ?");
                $student_query->bind_param("i", $student_id);
                $student_query->execute();
                $student_info = $student_query->get_result()->fetch_assoc();
                $student_name = $student_info['first_name'] . ' ' . $student_info['last_name'];
                
                logActivity($conn, $user['id'], 'UPDATE', 'schools_attended', $record_id, 
                           "Updated Grade {$grade_level} - Section {$_POST['section']} record for $student_name (LRN: {$student_info['lrn']}) at {$_POST['school_name']}");
                
                $_SESSION['success_message'] = "School record updated successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to update school record";
            }
    }
    
    header("Location: grade_progression.php?student_id=$student_id");
    exit();
}

// Handle Delete School Record
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && $is_admin) {
    $record_id = (int)$_GET['id'];
    $student_id = (int)$_GET['student_id'];
    
    // Get record details before deleting for logging
    $stmt = $conn->prepare("SELECT sa.*, s.first_name, s.last_name, s.lrn 
                            FROM schools_attended sa 
                            LEFT JOIN students s ON sa.student_id = s.id 
                            WHERE sa.id = ?");
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    
    if ($record) {
        // Delete the record
        $delete_stmt = $conn->prepare("DELETE FROM schools_attended WHERE id = ?");
        $delete_stmt->bind_param("i", $record_id);
        
        if ($delete_stmt->execute()) {
            $student_name = $record['first_name'] . ' ' . $record['last_name'];
            logActivity($conn, $user['id'], 'DELETE', 'schools_attended', $record_id, 
                       "Deleted Grade {$record['grade_level']} record for $student_name (LRN: {$record['lrn']})");
            
            $_SESSION['success_message'] = "School record deleted successfully";
        } else {
            $_SESSION['error_message'] = "Failed to delete school record";
        }
    } else {
        $_SESSION['error_message'] = "School record not found";
    }
    
    header("Location: grade_progression.php?student_id=$student_id");
    exit();
}

// Get student ID from URL
if (!isset($_GET['student_id'])) {
    $_SESSION['error_message'] = "No student selected.";
    header('Location: students.php');
    exit();
}

$student_id = (int)$_GET['student_id'];

// Get student information
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    $_SESSION['error_message'] = "Student not found.";
    header('Location: students.php');
    exit();
}

// Function to get school details with fallback to adviser's info
function getSchoolInfo($conn, $school_row) {
    $info = [
        'school_name' => $school_row['school_name'] ?? '',
        'school_id' => $school_row['school_id'] ?? '',
        'district' => $school_row['district'] ?? '',
        'division' => $school_row['division'] ?? '',
        'region' => $school_row['region'] ?? ''
    ];

    // If any critical field is missing, try to fetch from the assigned adviser in the system
    if (empty($info['school_name']) || empty($info['school_id']) || empty($info['district'])) {
        $adviser_name = $school_row['adviser_name'] ?? '';
        $user_match = null;

        // 1. Try matching by name first
        if (!empty($adviser_name)) {
            $stmt = $conn->prepare("SELECT school_name, school_id, district, division, region FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
            $stmt->bind_param("s", $adviser_name);
            $stmt->execute();
            $user_match = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        // 2. If no match by name, try matching by assignment (for internal records)
        if (!$user_match) {
            $grade_label = $school_row['grade_level'] ?? '';
            $section = $school_row['section'] ?? '';
            $school_year_str = $school_row['school_year'] ?? '';
            
            if ($grade_label && $section && $school_year_str) {
                // For grade_progression.php, $grade_label is already numeric int
                $gl_num = (int)$grade_label;

                if ($gl_num) {
                    $stmt = $conn->prepare("SELECT u.school_name, u.school_id, u.district, u.division, u.region 
                                           FROM teacher_assignments ta
                                           JOIN users u ON ta.teacher_id = u.id
                                           JOIN school_years sy ON ta.school_year_id = sy.id
                                           WHERE ta.grade_level = ? 
                                           AND ta.section = ? 
                                           AND ta.assignment_type = 'adviser'
                                           AND sy.year = ?
                                           LIMIT 1");
                    $stmt->bind_param("iss", $gl_num, $section, $school_year_str);
                    $stmt->execute();
                    $user_match = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
            }
        }

        // 3. Last fallback: try any admin user's school info as a global default
        if (!$user_match) {
            $admin_query = $conn->query("SELECT school_name, school_id, district, division, region FROM users WHERE role = 'admin' AND school_name IS NOT NULL AND school_name != '' LIMIT 1");
            if ($admin_query && $admin_query->num_rows > 0) {
                $user_match = $admin_query->fetch_assoc();
            }
        }

        if ($user_match) {
            if (empty($info['school_name'])) $info['school_name'] = $user_match['school_name'] ?? '';
            if (empty($info['school_id'])) $info['school_id'] = $user_match['school_id'] ?? '';
            if (empty($info['district'])) $info['district'] = $user_match['district'] ?? '';
            if (empty($info['division'])) $info['division'] = $user_match['division'] ?? '';
            if (empty($info['region'])) $info['region'] = $user_match['region'] ?? '';
        }
    }

    return $info;
}

// Function to get adviser full name from system if available
function getAdviserFullName($conn, $school_row) {
    if (!$school_row) return '';
    $adviser_name = $school_row['adviser_name'] ?? '';
    
    // 1. Try matching existing adviser_name to a user in the system to get their latest full name
    if (!empty($adviser_name)) {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE LOWER(full_name) = LOWER(?) LIMIT 1");
        $stmt->bind_param("s", $adviser_name);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['full_name'];
        }
        $stmt->close();
    }
    
    // 2. If no name or no match, look up by assignment (for internal records with missing adviser_name)
    $grade_label = $school_row['grade_level'] ?? '';
    $section = $school_row['section'] ?? '';
    $school_year_str = $school_row['school_year'] ?? '';
    
    if ($grade_label && $section && $school_year_str) {
        $gl_num = (int)$grade_label;
        
        if ($gl_num) {
            $stmt = $conn->prepare("SELECT u.full_name 
                                   FROM teacher_assignments ta
                                   JOIN users u ON ta.teacher_id = u.id
                                   JOIN school_years sy ON ta.school_year_id = sy.id
                                   WHERE ta.grade_level = ? 
                                   AND ta.section = ? 
                                   AND ta.assignment_type = 'adviser'
                                   AND sy.year = ?
                                   LIMIT 1");
            $stmt->bind_param("iss", $gl_num, $section, $school_year_str);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return $row['full_name'];
            }
            $stmt->close();
        }
    }
    
    return $adviser_name;
}

// Security Check: Ensure teacher has access to this student
if (!has_teacher_access_to_student($conn, $user['id'], $student_id)) {
    header('Location: students.php');
    exit();
}

// Get all school records for this student
$stmt = $conn->prepare("SELECT * FROM schools_attended WHERE student_id = ? ORDER BY grade_level ASC, display_order ASC, school_year ASC, id ASC");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$records_result = $stmt->get_result();
$records = [];
while ($row = $records_result->fetch_assoc()) {
    $records[$row['grade_level']][] = $row;
}

// Handle success/error messages
$success = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

include "../templates/header.php";
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-bar-chart-steps"></i> Grade Progression</h2>
        <p class="subtitle">Manage student's academic progression from Grade 1 to Grade 6</p>
    </div>
    <div>
        <a href="students.php" class="btn btn-info">
            <i class="bi bi-arrow-left"></i> Back to All Students
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
  <div class="alert alert-success alert-dis
    <i class="bi bi-check-circle"></i> <?= $success === '1' ? 'School record saved successfully!' : htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- Student Info Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-person-badge"></i> Student Information</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <strong>Student Name:</strong>
                <p class="mb-0"><?= htmlspecialchars(trim($student['first_name'] . ' ' . ($student['middle_name'] ?: '') . ' ' . $student['last_name'])) ?></p>
            </div>
            <div class="col-md-2">
                <strong>LRN:</strong>
                <p class="mb-0"><?= htmlspecialchars($student['lrn'] ?: '-') ?></p>
            </div>
            <div class="col-md-2">
                <strong>Gender:</strong>
                <p class="mb-0"><?= htmlspecialchars($student['gender'] ?: '-') ?></p>
            </div>
            <div class="col-md-4">
                <strong>Birthdate:</strong>
                <p class="mb-0">
                    <?php 
                    if ($student['birthdate']) {
                        echo date('F j, Y', strtotime($student['birthdate']));
                    } else {
                        echo '-';
                    }
                    ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Grade Progression Cards -->
<h5 class="mb-3"><i class="bi bi-ladder"></i> Elementary School Progress (Grade 1-6)</h5>

<?php for ($grade = 1; $grade <= 6; $grade++): ?>
    <?php 
    $grade_records = isset($records[$grade]) ? $records[$grade] : [];
    ?>
    
    <div class="card mb-4">
        <div class="card-header <?= !empty($grade_records) ? 'bg-success' : 'bg-secondary' ?> text-white d-flex justify-content-between align-items-center">
            <strong><i class="bi <?= !empty($grade_records) ? 'bi-check-circle-fill' : 'bi-dash-circle' ?> me-2"></i> Grade <?= $grade ?></strong>
            <?php if (!empty($grade_records)): ?>
                <span class="badge bg-light text-dark"><?= count($grade_records) ?> Record(s)</span>
            <?php endif; ?>
        </div>
        <div class="card-body <?= !empty($grade_records) ? 'p-0' : '' ?>">
            <?php if (!empty($grade_records)): ?>
                <?php foreach ($grade_records as $idx => $record): ?>
                    <div class="p-4 <?= $idx > 0 ? 'border-top border-2' : '' ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0 text-primary">
                                        <i class="bi bi-calendar3 me-2"></i> School Year: <?= htmlspecialchars($record['school_year'] ?: '-') ?>
                                        <?php if ($record['is_transfer']): ?>
                                            <span class="badge bg-info ms-2">Transferee</span>
                                        <?php endif; ?>
                                    </h5>
                                </div>
                                
                                <?php $school_info = getSchoolInfo($conn, $record); ?>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">School Name:</small>
                                        <strong><?= htmlspecialchars($school_info['school_name'] ?: '-') ?></strong>
                                    </div>
                                    <div class="col-md-2">
                                        <small class="text-muted d-block">School ID:</small>
                                        <strong><?= htmlspecialchars($school_info['school_id'] ?: '-') ?></strong>
                                    </div>
                                    <div class="col-md-2">
                                        <small class="text-muted d-block">Section:</small>
                                        <strong><?= htmlspecialchars(strtoupper($record['section'] ?: '-')) ?></strong>
                                    </div>
                                    <div class="col-md-2">
                                        <small class="text-muted d-block">District:</small>
                                        <strong><?= htmlspecialchars($school_info['district'] ?: '-') ?></strong>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Division:</small>
                                        <strong><?= htmlspecialchars($school_info['division'] ?: '-') ?></strong>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Region:</small>
                                        <strong><?= htmlspecialchars($school_info['region'] ?: '-') ?></strong>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted d-block">Adviser:</small>
                                        <strong><?= htmlspecialchars(strtoupper(getAdviserFullName($conn, $record) ?: '-')) ?></strong>
                                    </div>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                <div class="d-flex gap-2 mt-3 align-items-center">
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#editForm<?= $record['id'] ?>">
                                        <i class="bi bi-pencil-square"></i> Edit Details
                                    </button>
                                    <a href="enter_grades.php?student_id=<?= $student_id ?>&school_attended_id=<?= $record['id'] ?>" class="btn btn-sm btn-warning">
                                        <i class="bi bi-clipboard-check"></i> Edit Grades
                                    </a>
                                    <a href="?action=delete&id=<?= $record['id'] ?>&student_id=<?= $student_id ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('Are you sure you want to delete this school record?')">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_admin && count($grade_records) > 1): ?>
                            <!-- Reordering Buttons (Up/Down) placed on the right -->
                            <div class="ms-4 d-flex flex-column gap-2 align-items-center justify-content-center" style="min-width: 100px;">
                                <div class="text-muted small mb-1 fw-bold">SEQUENCE</div>
                                <button type="button" class="btn btn-outline-primary reorder-btn-lg" 
                                        onclick="reorderRecord(<?= $record['id'] ?>, 'up')" 
                                        title="Move Up"
                                        <?= $idx === 0 ? 'disabled' : '' ?>>
                                    <i class="bi bi-chevron-up fs-4"></i>
                                    <span class="d-block small">MOVE UP</span>
                                </button>
                                <button type="button" class="btn btn-outline-primary reorder-btn-lg" 
                                        onclick="reorderRecord(<?= $record['id'] ?>, 'down')" 
                                        title="Move Down"
                                        <?= $idx === count($grade_records) - 1 ? 'disabled' : '' ?>>
                                    <i class="bi bi-chevron-down fs-4"></i>
                                    <span class="d-block small">MOVE DOWN</span>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($is_admin): ?>
                        <!-- Edit Record Form (Collapsible) -->
                        <div class="collapse mt-3" id="editForm<?= $record['id'] ?>">
                            <div class="card card-body shadow-sm">
                                <h6 class="mb-3 text-primary"><i class="bi bi-pencil-square me-2"></i>Edit School Record Details (SY <?= htmlspecialchars($record['school_year']) ?>)</h6>
                                <form method="POST" action="grade_progression.php" class="needs-validation" novalidate>
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="record_id" value="<?= $record['id'] ?>">
                                    <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                    <input type="hidden" name="grade_level" value="<?= $grade ?>">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-body">School Year <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="school_year" 
                                                   value="<?= htmlspecialchars($record['school_year']) ?>"
                                                   placeholder="e.g., 2023-2024" 
                                                   pattern="\d{4}-\d{4}"
                                                   required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-body">School Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="school_name" 
                                                   value="<?= htmlspecialchars($school_info['school_name'] ?: '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-body">School ID</label>
                                            <input type="text" class="form-control" name="school_id" 
                                                   value="<?= htmlspecialchars($school_info['school_id'] ?: '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-body">District</label>
                                            <input type="text" class="form-control" name="district" 
                                                   value="<?= htmlspecialchars($school_info['district'] ?: '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-body">Division</label>
                                            <input type="text" class="form-control" name="division" 
                                                   value="<?= htmlspecialchars($school_info['division'] ?: '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-body">Region</label>
                                            <input type="text" class="form-control" name="region" 
                                                   value="<?= htmlspecialchars($school_info['region'] ?: '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-body">Section <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="section" 
                                                   value="<?= htmlspecialchars($record['section']) ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label text-body">Adviser Name</label>
                                            <input type="text" class="form-control" name="adviser_name" 
                                                   value="<?= htmlspecialchars(getAdviserFullName($conn, $record) ?: '') ?>">
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#editForm<?= $record['id'] ?>">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php if ($is_admin): ?>
                    <div class="p-3 border-top text-center">
                        <button type="button" class="btn btn-sm btn-info text-white shadow-sm" data-bs-toggle="collapse" data-bs-target="#addForm<?= $grade ?>">
                            <i class="bi bi-plus-circle"></i> Add Another Record for Grade <?= $grade ?>
                        </button>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="p-4 text-center">
                    <p class="text-muted mb-3">No record for this grade level</p>
                    <?php if ($is_admin): ?>
                        <button type="button" class="btn btn-info text-white shadow-sm" data-bs-toggle="collapse" data-bs-target="#addForm<?= $grade ?>">
                            <i class="bi bi-plus-circle"></i> Add School Record
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($is_admin): ?>
                <!-- Add New Record Form (Shared for both cases) -->
                <div class="collapse p-3 border-top" id="addForm<?= $grade ?>">
                    <div class="card card-body shadow-sm mb-0">
                        <h6 class="mb-3 text-info"><i class="bi bi-plus-circle me-2"></i>Add New School Record for Grade <?= $grade ?></h6>
                        <form method="POST" action="grade_progression.php" class="needs-validation" novalidate>
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="student_id" value="<?= $student_id ?>">
                            <input type="hidden" name="grade_level" value="<?= $grade ?>">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">School Year <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="school_year" placeholder="e.g., 2023-2024" pattern="\d{4}-\d{4}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">School Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="school_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">School ID</label>
                                    <input type="text" class="form-control" name="school_id">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">District</label>
                                    <input type="text" class="form-control" name="district">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Division</label>
                                    <input type="text" class="form-control" name="division">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Region</label>
                                    <input type="text" class="form-control" name="region">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Section <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="section" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Adviser Name</label>
                                    <input type="text" class="form-control" name="adviser_name">
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary btn-sm">Save Record</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#addForm<?= $grade ?>">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endfor; ?>

<script>
// Bootstrap form validation - MUST prevent submission if invalid
(function() {
    'use strict';
    
    // Add submit handler to all forms when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Re-attach validation whenever a collapsed section is shown
        document.querySelectorAll('.collapse').forEach(function(collapseEl) {
            collapseEl.addEventListener('shown.bs.collapse', function() {
                attachValidation();
            });
        });
        
        // Initial attachment
        attachValidation();
    });
    
    function attachValidation() {
        const forms = document.querySelectorAll('.needs-validation');
        forms.forEach(function(form) {
            // Remove old listeners by cloning
            const newForm = form.cloneNode(true);
            form.parentNode.replaceChild(newForm, form);
            
            // Add submit listener
            newForm.addEventListener('submit', function(event) {
                if (!newForm.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    
                    // Scroll to first invalid field
                    const firstInvalid = newForm.querySelector(':invalid');
                    if (firstInvalid) {
                        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        setTimeout(function() {
                            firstInvalid.focus();
                        }, 300);
                    }
                }
                newForm.classList.add('was-validated');
            }, false);
        });
    }
})();

function reorderRecord(recordId, direction) {
    if (!confirm(`Are you sure you want to move this record ${direction}?`)) return;
    
    const btn = event.currentTarget;
    btn.disabled = true;
    
    fetch(`?ajax=reorder_school&student_id=<?= $student_id ?>`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: recordId, direction: direction })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error reordering record: ' + (data.message || 'Unknown error'));
            btn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to reorder record');
        btn.disabled = false;
    });
}

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
    
    // Auto-expand edit form for specific grade if expand_grade parameter exists
    const urlParams = new URLSearchParams(window.location.search);
    const expandGrade = urlParams.get('expand_grade');
    if (expandGrade) {
        // Find and expand the edit form for the specified grade
        const editForm = document.getElementById('editForm' + expandGrade);
        if (editForm) {
            const collapseElement = new bootstrap.Collapse(editForm, {
                show: true
            });
            
            // Scroll to the grade card
            setTimeout(() => {
                editForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);
        }
    }
});
</script>

<style>
/* Reordering buttons */
.reorder-btn-lg {
    width: 80px;
    height: 60px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 5px;
    border-width: 2px;
    transition: all 0.2s;
}

.reorder-btn-lg:hover:not(:disabled) {
    background-color: var(--primary-color);
    color: white;
    transform: scale(1.05);
}

.reorder-btn-lg:disabled {
    opacity: 0.2;
    cursor: not-allowed;
    border-color: #dee2e6;
}

.reorder-btn-lg i {
    line-height: 1;
    margin-bottom: 2px;
}

/* Card styling refinements */
.card-header {
    padding: 1rem 1.25rem;
    font-weight: 500;
}

.card-header.bg-success {
    background-color: #28a745 !important;
    color: #ffffff !important;
}

.card-header.bg-secondary {
    background-color: #6c757d !important;
    color: #ffffff !important;
}

.card-header.bg-primary {
    background-color: #007bff !important;
    color: #ffffff !important;
}

/* Badge styling */
.badge.bg-light {
    background-color: rgba(255,255,255,0.9) !important;
    color: #000 !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start !important;
    }
    
    .card-header .badge {
        margin-top: 0.5rem;
    }
}
</style>

<?php include '../templates/footer.php'; ?>
