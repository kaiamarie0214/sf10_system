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

// Handle Add School Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add' && $is_admin) {
    $student_id = (int)$_POST['student_id'];
    $grade_level = (int)$_POST['grade_level'];
    $school_year = trim($_POST['school_year']);
    
    // Validate school year format (YYYY-YYYY)
    if (!preg_match('/^\d{4}-\d{4}$/', $school_year)) {
        $_SESSION['error_message'] = "Invalid school year format. Use YYYY-YYYY";
    } else {
        // Check for duplicate record (same student, grade level, and school year)
        $check_duplicate = $conn->prepare("SELECT id, section FROM schools_attended 
                                           WHERE student_id = ? AND grade_level = ? AND school_year = ?");
        $check_duplicate->bind_param("iis", $student_id, $grade_level, $school_year);
        $check_duplicate->execute();
        $result = $check_duplicate->get_result();
        
        if ($result->num_rows > 0) {
            $existing = $result->fetch_assoc();
            $_SESSION['error_message'] = "A school record already exists for this student in Grade {$grade_level} - {$existing['section']} for School Year {$school_year}.";
        } else {
            // Insert new school record - always set is_transfer = 1 (transfer) by default
            $stmt = $conn->prepare("INSERT INTO schools_attended 
                (student_id, school_name, school_id, district, division, region, grade_level, section, school_year, adviser_name, is_transfer)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            
            $stmt->bind_param("isssssisss",
                $student_id,
                $_POST['school_name'],
                $_POST['school_id'],
                $_POST['district'],
                $_POST['division'],
                $_POST['region'],
                $grade_level,
                $_POST['section'],
                $school_year,
                $_POST['adviser_name']
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
        // Check for duplicate record (excluding current record)
        $check_duplicate = $conn->prepare("SELECT id, section FROM schools_attended 
                                           WHERE student_id = ? AND grade_level = ? AND school_year = ? AND id != ?");
        $check_duplicate->bind_param("iisi", $student_id, $grade_level, $school_year, $record_id);
        $check_duplicate->execute();
        $result = $check_duplicate->get_result();
        
        if ($result->num_rows > 0) {
            $existing = $result->fetch_assoc();
            $_SESSION['error_message'] = "A school record already exists for this student in Grade {$grade_level} - {$existing['section']} for School Year {$school_year}.";
        } else {
            $stmt = $conn->prepare("UPDATE schools_attended SET 
                school_name = ?, school_id = ?, district = ?, division = ?, region = ?,
                section = ?, school_year = ?, adviser_name = ?
                WHERE id = ?");
            
            $stmt->bind_param("ssssssssi",
                $_POST['school_name'],
                $_POST['school_id'],
                $_POST['district'],
                $_POST['division'],
                $_POST['region'],
                $_POST['section'],
                $school_year,
                $_POST['adviser_name'],
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

// Security Check: Ensure teacher has access to this student
if (!has_teacher_access_to_student($conn, $user['id'], $student_id)) {
    header('Location: students.php');
    exit();
}

// Get all school records for this student
$stmt = $conn->prepare("SELECT * FROM schools_attended WHERE student_id = ? ORDER BY grade_level ASC");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$records_result = $stmt->get_result();
$records = [];
while ($row = $records_result->fetch_assoc()) {
    $records[$row['grade_level']] = $row;
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
    $has_record = isset($records[$grade]);
    $record = $has_record ? $records[$grade] : null;
    ?>
    
    <div class="card mb-3">
        <?php if ($has_record): ?>
            <!-- Grade with Record -->
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-check-circle-fill me-2"></i> Grade <?= $grade ?></strong>
                <span class="badge bg-light text-dark"><?= htmlspecialchars($record['school_year'] ?: '-') ?></span>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <small class="text-muted">School Name:</small>
                        <p class="mb-0"><?= htmlspecialchars($record['school_name'] ?: '-') ?></p>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted">School ID:</small>
                        <p class="mb-0"><?= htmlspecialchars($record['school_id'] ?: '-') ?></p>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted">Section:</small>
                        <p class="mb-0"><?= htmlspecialchars(strtoupper($record['section'] ?: '-')) ?></p>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted">District:</small>
                        <p class="mb-0"><?= htmlspecialchars($record['district'] ?: '-') ?></p>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Division:</small>
                        <p class="mb-0"><?= htmlspecialchars($record['division'] ?: '-') ?></p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3">
                        <small class="text-muted">Region:</small>
                        <p class="mb-0"><?= htmlspecialchars($record['region'] ?: '-') ?></p>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Adviser:</small>
                        <p class="mb-0"><?= htmlspecialchars(strtoupper($record['adviser_name'] ?: '-')) ?></p>
                    </div>
                </div>
                
                <?php if ($is_admin): ?>
                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#editForm<?= $grade ?>">
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
                
                <!-- Edit Record Form (Collapsible) -->
                <div class="collapse mt-3" id="editForm<?= $grade ?>">
                    <hr>
                    <h6 class="mb-3">Edit School Record Details</h6>
                    <form method="POST" action="grade_progression.php" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="record_id" value="<?= $record['id'] ?>">
                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                        <input type="hidden" name="grade_level" value="<?= $grade ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">School Year <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="school_year" 
                                       value="<?= htmlspecialchars($record['school_year']) ?>"
                                       placeholder="e.g., 2023-2024" 
                                       pattern="\d{4}-\d{4}"
                                       title="Format: YYYY-YYYY (e.g., 2023-2024)"
                                       required>
                                <div class="invalid-feedback">Please enter school year in format YYYY-YYYY</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">School Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="school_name" 
                                       value="<?= htmlspecialchars($record['school_name']) ?>" required>
                                <div class="invalid-feedback">Please enter school name</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">School ID</label>
                                <input type="text" class="form-control" name="school_id" 
                                       value="<?= htmlspecialchars($record['school_id'] ?: '') ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">District</label>
                                <input type="text" class="form-control" name="district" 
                                       value="<?= htmlspecialchars($record['district'] ?: '') ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Division</label>
                                <input type="text" class="form-control" name="division" 
                                       value="<?= htmlspecialchars($record['division'] ?: '') ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Region</label>
                                <input type="text" class="form-control" name="region" 
                                       value="<?= htmlspecialchars($record['region'] ?: '') ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Section <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="section" 
                                       value="<?= htmlspecialchars($record['section']) ?>" required>
                                <div class="invalid-feedback">Please enter section</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Adviser Name</label>
                                <input type="text" class="form-control" name="adviser_name" 
                                       value="<?= htmlspecialchars($record['adviser_name'] ?: '') ?>">
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Update Record
                            </button>
                            <button type="button" class="btn btn-secondary" data-bs-toggle="collapse" data-bs-target="#editForm<?= $grade ?>">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- Grade without Record -->
            <div class="card-header bg-secondary text-white">
                <strong><i class="bi bi-dash-circle me-2"></i> Grade <?= $grade ?></strong>
            </div>
            <div class="card-body">
                <?php if ($is_admin): ?>
                    <!-- Add New Record Form -->
                    <p class="text-muted text-center mb-3">
                        <i class="bi bi-info-circle"></i> No record for this grade level
                    </p>
                    <div class="text-center">
                        <button type="button" class="btn btn-info" data-bs-toggle="collapse" data-bs-target="#addForm<?= $grade ?>">
                            <i class="bi bi-plus-circle"></i> Add School Record
                        </button>
                    </div>
                    
                    <div class="collapse mt-3" id="addForm<?= $grade ?>">
                        <form method="POST" action="grade_progression.php" class="needs-validation" novalidate>
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="student_id" value="<?= $student_id ?>">
                            <input type="hidden" name="grade_level" value="<?= $grade ?>">
                            
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">School Year <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="school_year" 
                                           placeholder="e.g., 2023-2024" 
                                           pattern="\d{4}-\d{4}"
                                           title="Format: YYYY-YYYY (e.g., 2023-2024)"
                                           required>
                                    <div class="invalid-feedback">Please enter school year in format YYYY-YYYY</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">School Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="school_name" required>
                                    <div class="invalid-feedback">Please enter school name</div>
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
                                    <div class="invalid-feedback">Please enter section</div>
                                </div>
                                
                                <div class="col-md-6">
                                    <label class="form-label">Adviser Name</label>
                                    <input type="text" class="form-control" name="adviser_name">
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Save Record
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Non-admin View -->
                    <p class="text-muted text-center mb-3">
                        <i class="bi bi-info-circle"></i> No record for this grade level
                    </p>
                    <div class="text-center">
                        <button class="btn btn-secondary btn-sm" disabled title="Only administrators can add school records">
                            <i class="bi bi-lock"></i> Admins Only
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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
/* Card styling to match modal design - Dark/Light Mode Support */
.card {
    background-color: var(--bs-body-bg);
    border-color: var(--bs-border-color);
    border-radius: 0.375rem;
}

.card-header {
    padding: 1rem 1.25rem;
    font-weight: 500;
}

.card-header.bg-success {
    background-color: #28a745 !important;
    color: #ffffff !important;
    border-color: #28a745 !important;
}

.card-header.bg-secondary {
    background-color: #6c757d !important;
    color: #ffffff !important;
    border-color: #6c757d !important;
}

.card-header.bg-primary {
    background-color: #007bff !important;
    color: #ffffff !important;
    border-color: #007bff !important;
}

.card-body {
    padding: 1.25rem;
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
}

/* Make form inputs more compact */
.needs-validation .form-control {
    font-size: 0.9rem;
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
    border-color: var(--bs-border-color);
}

.needs-validation .form-control:focus {
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
    border-color: #80bdff;
}

.needs-validation .form-label {
    font-weight: 500;
    margin-bottom: 0.25rem;
    font-size: 0.9rem;
    color: var(--bs-body-color);
}

.text-muted {
    color: var(--bs-secondary-color) !important;
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
