<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/logger.php";

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];
$error = "";
$success = "";

// Set current page for sidebar navigation to 'records' so it stays active
$_SERVER['PHP_SELF'] = 'students.php';

// Handle Add Student
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $error = "Student with LRN '{$_POST['lrn']}' already exists. Please check the student's record.";
    } elseif ($check_name->num_rows > 0) {
        $error = "A student with a similar name already exists: '{$_POST['first_name']} {$middle_name} {$_POST['last_name']}'. Please verify the student information or check if they are already enrolled.";
    } else {
        // Handle credential_presented (radio button)
        $credential_presented = isset($_POST['credential_presented']) ? $_POST['credential_presented'] : '';
        
        $stmt = $conn->prepare("INSERT INTO students 
            (lrn, last_name, first_name, middle_name, suffix, gender, birthdate,
             credential_presented, eligibility_school_name, eligibility_school_id, eligibility_school_address,
             pept_rating, pept_exam_date, pept_testing_center, credential_other_details, eligibility_remark)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param(
            "ssssssssssssssss",
            $_POST['lrn'], $_POST['last_name'], $_POST['first_name'], $_POST['middle_name'], $_POST['suffix'],
            $_POST['gender'], $_POST['birthdate'], $credential_presented,
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
            
            // Redirect directly to grade progression page
            header("Location: grade_progression.php?student_id=$new_student_id");
            exit();
        } else {
            $error = "Failed to add student. Please try again.";
        }
    }
}

include "../templates/header.php";
?>

<div class="d-flex justify-content-between align-items-start mb-4">
        <div class="page-header mb-0">
            <h2><i class="bi bi-person-plus"></i> Add New Student</h2>
            <p class="subtitle">Fill in the student information below</p>
        </div>
        <a href="students.php" class="btn btn-info">
            <i class="bi bi-arrow-left"></i> Back to All Students
        </a>
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

    <div class="card">
        <div class="card-body">
            <form method="POST" action="add_student.php">
                <!-- Basic Information -->
                <h5 class="mb-3"><i class="bi bi-person-vcard"></i> Basic Information</h5>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">LRN <span class="text-danger">*</span></label>
                        <input type="text" name="lrn" class="form-control" required 
                               value="<?= isset($_POST['lrn']) ? htmlspecialchars($_POST['lrn']) : '' ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Gender <span class="text-danger">*</span></label>
                        <select name="gender" class="form-control" required>
                            <option value="">-- Select Gender --</option>
                            <option value="Male" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Male') ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Birthdate <span class="text-danger">*</span></label>
                        <input type="date" name="birthdate" class="form-control" required
                               value="<?= isset($_POST['birthdate']) ? htmlspecialchars($_POST['birthdate']) : '' ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control" required
                               value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control" required
                               value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                               value="<?= isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Suffix</label>
                        <input type="text" name="suffix" class="form-control" placeholder="Jr., Sr., III, etc."
                               value="<?= isset($_POST['suffix']) ? htmlspecialchars($_POST['suffix']) : '' ?>">
                    </div>
                </div>

                <hr class="my-4">

                <!-- Credential Presented -->
                <h5 class="mb-3"><i class="bi bi-file-text"></i> Credential Presented</h5>
                <div class="row mb-3">
                    <div class="col-md-12">
                        <small class="text-muted">Selected credentials will be saved automatically</small>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Eligibility for Elementary Enrollment -->
                <h5 class="mb-3"><i class="bi bi-mortarboard"></i> Eligibility for Elementary School Enrollment</h5>
                
                <div class="mb-3">
                    <label class="form-label">Credential Presented for Grade 1:</label><br>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="credential_presented" value="Kinder Progress Report" id="credKinder"
                               <?= (isset($_POST['credential_presented']) && $_POST['credential_presented'] === 'Kinder Progress Report') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="credKinder">Kinder Progress Report</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="credential_presented" value="ECCD Checklist" id="credECCD"
                               <?= (isset($_POST['credential_presented']) && $_POST['credential_presented'] === 'ECCD Checklist') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="credECCD">ECCD Checklist</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="credential_presented" value="Kindergarten Certificate of Completion" id="credKinderCert"
                               <?= (isset($_POST['credential_presented']) && $_POST['credential_presented'] === 'Kindergarten Certificate of Completion') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="credKinderCert">Kindergarten Certificate of Completion</label>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">School Name</label>
                        <input type="text" name="eligibility_school_name" class="form-control"
                               value="<?= isset($_POST['eligibility_school_name']) ? htmlspecialchars($_POST['eligibility_school_name']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">School ID</label>
                        <input type="text" name="eligibility_school_id" class="form-control"
                               value="<?= isset($_POST['eligibility_school_id']) ? htmlspecialchars($_POST['eligibility_school_id']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PEPT Exam Date</label>
                        <input type="date" name="pept_exam_date" class="form-control"
                               value="<?= isset($_POST['pept_exam_date']) ? htmlspecialchars($_POST['pept_exam_date']) : '' ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">School Address</label>
                        <input type="text" name="eligibility_school_address" class="form-control"
                               value="<?= isset($_POST['eligibility_school_address']) ? htmlspecialchars($_POST['eligibility_school_address']) : '' ?>">
                    </div>
                </div>

                <h6 class="mb-3">Other Credential Presented</h6>
                <div class="row mb-3">
                    <div class="col-md-2">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_pept_passer" value="1" id="peptPasser"
                                   <?= (isset($_POST['is_pept_passer']) && $_POST['is_pept_passer'] == '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="peptPasser">PEPT Passer</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Rating:</label>
                        <input type="text" name="pept_rating" class="form-control"
                               value="<?= isset($_POST['pept_rating']) ? htmlspecialchars($_POST['pept_rating']) : '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date of Examination (mm/dd/yyyy)</label>
                        <input type="date" name="pept_exam_date" class="form-control"
                               value="<?= isset($_POST['pept_exam_date']) ? htmlspecialchars($_POST['pept_exam_date']) : '' ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Name and Address of Testing Center</label>
                        <input type="text" name="pept_testing_center" class="form-control"
                               value="<?= isset($_POST['pept_testing_center']) ? htmlspecialchars($_POST['pept_testing_center']) : '' ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Others (Pls. Specify):</label>
                        <input type="text" name="credential_other_details" class="form-control"
                               value="<?= isset($_POST['credential_other_details']) ? htmlspecialchars($_POST['credential_other_details']) : '' ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Remark:</label>
                        <input type="text" name="eligibility_remark" class="form-control" 
                               value="<?= isset($_POST['eligibility_remark']) ? htmlspecialchars($_POST['eligibility_remark']) : '' ?>">
                    </div>
                </div>

                <hr class="my-4">

                <div class="d-flex gap-2 justify-content-end">
                    <a href="students.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Student
                    </button>
                </div>
            </form>
        </div>
    </div>

<script>
// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function() {
    // Submit form on Enter key from any input/select (except textarea)
    document.querySelector('form[action="add_student.php"]').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            this.submit();
        }
    });

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
});
</script>

<style>
.breadcrumb {
    background-color: transparent;
    padding: 0;
    margin-bottom: 0;
}

.breadcrumb-item + .breadcrumb-item::before {
    content: ">";
}

.breadcrumb-item a {
    text-decoration: none;
    color: var(--primary-color);
}

.breadcrumb-item.active {
    color: #6c757d;
}

.card {
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
}

.form-label {
    font-weight: 500;
}

h5 {
    color: var(--primary-color);
    font-weight: 600;
}
</style>

<?php include '../templates/footer.php'; ?>
