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

// Set current page for sidebar navigation to 'records' so it stays active
$_SERVER['PHP_SELF'] = 'students.php';

// Get student ID from URL
if (!isset($_GET['id'])) {
    header("Location: students.php");
    exit;
}

$student_id = (int)$_GET['id'];

// Fetch student details
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student_result = $stmt->get_result();

if ($student_result->num_rows === 0) {
    header("Location: students.php");
    exit;
}

$student = $student_result->fetch_assoc();

// Security Check: Ensure teacher has access to this student
if (!has_teacher_access_to_student($conn, $user['id'], $student_id)) {
    header("Location: students.php");
    exit;
}


include "../templates/header.php";
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-eye"></i> Student Details</h2>
        <p class="subtitle">View student information</p>
    </div>
    <a href="students.php" class="btn btn-info">
        <i class="bi bi-arrow-left"></i> Back to All Students
    </a>
</div>

<div class="card">
    <div class="card-body">
        <!-- Basic Information -->
        <h5 class="mb-3"><i class="bi bi-person-vcard"></i> Basic Information</h5>
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">LRN</label>
                <input type="text" class="form-control" readonly value="<?= htmlspecialchars($student['lrn']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Gender</label>
                <input type="text" class="form-control" readonly value="<?= htmlspecialchars($student['gender']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Birthdate</label>
                <input type="text" class="form-control" readonly value="<?= htmlspecialchars($student['birthdate']) ?>">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label">Last Name</label>
                <input type="text" class="form-control" readonly value="<?= htmlspecialchars($student['last_name']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" readonly value="<?= htmlspecialchars($student['first_name']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['middle_name']) ? htmlspecialchars($student['middle_name']) : '' ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Suffix</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['suffix']) ? htmlspecialchars($student['suffix']) : '' ?>">
            </div>
        </div>

        <hr class="my-4">

        <!-- Credential Presented -->
        <h5 class="mb-3"><i class="bi bi-file-text"></i> Credential Presented</h5>
        
        <hr class="my-4">

        <!-- Eligibility for Elementary Enrollment -->
        <h5 class="mb-3"><i class="bi bi-mortarboard"></i> Eligibility for Elementary School Enrollment</h5>
        
        <div class="mb-3">
            <label class="form-label">Credential Presented for Grade 1:</label><br>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="credential_presented" value="Kinder Progress Report" id="credKinder" <?php if($student['credential_presented'] === 'Kinder Progress Report') echo 'checked="checked"'; ?> onclick="return false;">
                <label class="form-check-label" for="credKinder">Kinder Progress Report</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="credential_presented" value="ECCD Checklist" id="credECCD" <?php if($student['credential_presented'] === 'ECCD Checklist') echo 'checked="checked"'; ?> onclick="return false;">
                <label class="form-check-label" for="credECCD">ECCD Checklist</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="credential_presented" value="Kindergarten Certificate of Completion" id="credKinderCert" <?php if($student['credential_presented'] === 'Kindergarten Certificate of Completion') echo 'checked="checked"'; ?> onclick="return false;">
                <label class="form-check-label" for="credKinderCert">Kindergarten Certificate of Completion</label>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">School Name</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['eligibility_school_name']) ? htmlspecialchars($student['eligibility_school_name']) : '' ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">School ID</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['eligibility_school_id']) ? htmlspecialchars($student['eligibility_school_id']) : '' ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">PEPT Exam Date</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['pept_exam_date']) ? htmlspecialchars($student['pept_exam_date']) : '' ?>">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-12">
                <label class="form-label">School Address</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['eligibility_school_address']) ? htmlspecialchars($student['eligibility_school_address']) : '' ?>">
            </div>
        </div>

        <h6 class="mb-3">Other Credential Presented</h6>
        <div class="row mb-3">
            <div class="col-md-2">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" disabled <?= !empty($student['pept_rating']) ? 'checked' : '' ?>>
                    <label class="form-check-label">PEPT Passer</label>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Rating:</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['pept_rating']) ? htmlspecialchars($student['pept_rating']) : '' ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Date of Examination (mm/dd/yyyy)</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['pept_exam_date']) ? htmlspecialchars($student['pept_exam_date']) : '' ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label">Name and Address of Testing Center</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['pept_testing_center']) ? htmlspecialchars($student['pept_testing_center']) : '' ?>">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Others (Pls. Specify):</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['credential_other_details']) ? htmlspecialchars($student['credential_other_details']) : '' ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Remark:</label>
                <input type="text" class="form-control" readonly value="<?= !empty($student['eligibility_remark']) ? htmlspecialchars($student['eligibility_remark']) : '' ?>">
            </div>
        </div>

        <hr class="my-4">

        <div class="d-flex gap-2 justify-content-end">
            <a href="students.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to All Students
            </a>
            <?php if ($user['role'] === 'admin'): ?>
            <a href="edit_student.php?id=<?= $student_id ?>" class="btn btn-warning">
                <i class="bi bi-pencil"></i> Edit Student
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
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
