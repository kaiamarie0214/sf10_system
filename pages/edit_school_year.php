<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/logger.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Get school year ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    $_SESSION['error'] = "Invalid school year ID.";
    header("Location: school_years.php");
    exit();
}

// Get school year details
$stmt = $conn->prepare("SELECT * FROM school_years WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$school_year = $stmt->get_result()->fetch_assoc();

if (!$school_year) {
    $_SESSION['error'] = "School year not found.";
    header("Location: school_years.php");
    exit();
}

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year = trim($_POST['year']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $status = $_POST['status'];
    
    // Validate dates
    if (strtotime($start_date) >= strtotime($end_date)) {
        $error = "End date must be after start date.";
    } else {
        // If setting as active, deactivate others
        if ($is_active) {
            $conn->query("UPDATE school_years SET is_active = 0");
        }
        
        $stmt = $conn->prepare("UPDATE school_years SET year = ?, is_active = ?, status = ?, start_date = ?, end_date = ? WHERE id = ?");
        $stmt->bind_param("sisssi", $year, $is_active, $status, $start_date, $end_date, $id);
        
        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'UPDATE', 'school_years', $id, "Updated school year: $year");
            
            // Update session if this is the current school year
            if ($_SESSION['school_year_id'] == $id) {
                $_SESSION['school_year'] = $year;
                $_SESSION['school_year_status'] = $status;
            }
            
            $_SESSION['success'] = "School year updated successfully!";
            header("Location: school_years.php");
            exit();
        } else {
            $error = "Failed to update school year: " . $conn->error;
        }
    }
}

// Set current page for sidebar navigation
$_SERVER['PHP_SELF'] = 'school_years.php';

$page_title = "Edit School Year";
include "../templates/header.php";
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-pencil-square"></i> <?= $page_title ?></h2>
        <p class="subtitle">Update school year information</p>
    </div>
    <a href="school_years.php" class="btn btn-info">
        <i class="bi bi-arrow-left"></i> Back to School Years
    </a>
</div>

<?php if ($error): ?>
    <div class='alert alert-danger alert-dismissible fade show' role='alert' id='errorAlert'>
        <i class="bi bi-exclamation-circle"></i> <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<script>
// Auto-dismiss alerts with fade out
document.addEventListener('DOMContentLoaded', function() {
    const errorAlert = document.getElementById('errorAlert');
    
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.transition = 'opacity 0.5s ease-out';
            errorAlert.style.opacity = '0';
            setTimeout(() => errorAlert.remove(), 500);
        }, 7000);
    }
});
</script>

<!-- Edit School Year Form -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-calendar3"></i> School Year Information
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">School Year <span class="text-danger">*</span></label>
                    <input type="text" name="year" class="form-control" placeholder="e.g., 2025-2026" 
                           value="<?= isset($_POST['year']) ? htmlspecialchars($_POST['year']) : htmlspecialchars($school_year['year']) ?>" 
                           autocomplete="off" required>
                    <small class="text-muted">Format: YYYY-YYYY</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-control" required>
                        <option value="">-- Select Status --</option>
                        <option value="active" <?= (isset($_POST['status']) ? $_POST['status'] : $school_year['status']) == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (isset($_POST['status']) ? $_POST['status'] : $school_year['status']) == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <small class="text-muted">Active school years can be used for enrollment</small>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Start Date <span class="text-danger">*</span></label>
                    <input type="date" name="start_date" class="form-control" 
                           value="<?= isset($_POST['start_date']) ? $_POST['start_date'] : $school_year['start_date'] ?>" required>
                    <small class="text-muted">First day of school year</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">End Date <span class="text-danger">*</span></label>
                    <input type="date" name="end_date" class="form-control" 
                           value="<?= isset($_POST['end_date']) ? $_POST['end_date'] : $school_year['end_date'] ?>" required>
                    <small class="text-muted">Last day of school year</small>
                </div>
            </div>
            
            <hr>
            
            <div class="mb-3">
                <div class="form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="isActive" 
                           <?= (isset($_POST['is_active']) || (!isset($_POST['is_active']) && $school_year['is_active'] == 1)) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isActive">
                        <strong>Set as current school year</strong>
                    </label>
                </div>
                <small class="text-muted">Only one school year can be marked as current. This will be the default selection for users.</small>
            </div>
            
            <!-- Form Actions -->
            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="school_years.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Update School Year
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Guidelines Card -->
<div class="card mt-3">
    <div class="card-header">
        <i class="bi bi-info-circle"></i> Guidelines
    </div>
    <div class="card-body">
        <ul class="mb-0">
            <li>School year format should be YYYY-YYYY (e.g., 2025-2026)</li>
            <li>Start date should be before the end date</li>
            <li>Only one school year can be marked as "current" at a time</li>
            <li>Active school years are available for enrollment and grading</li>
            <li>Inactive school years are hidden from regular operations</li>
        </ul>
    </div>
</div>

<?php include "../templates/footer.php"; ?>
