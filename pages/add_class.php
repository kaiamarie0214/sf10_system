<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/logger.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];
$is_admin = ($user['role'] === 'admin');

// Admin only access
if (!$is_admin) {
    header("Location: dashboard.php");
    exit();
}

$success = "";
$error = "";

// Get current school year from selected session year, fallback to active year, then calendar default
$currentSchoolYear = $_SESSION['school_year'] ?? null;
if (empty($currentSchoolYear)) {
    $sy_row = $conn->query("SELECT year FROM school_years WHERE is_active = 1 LIMIT 1");
    if ($sy_row && $sy_row->num_rows > 0) {
        $currentSchoolYear = $sy_row->fetch_assoc()['year'];
    }
}
if (empty($currentSchoolYear)) {
    $currentYear = date("Y");
    $nextYear = $currentYear + 1;
    $currentSchoolYear = "$currentYear-$nextYear";
}

// Handle Add Class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class'])) {
    $grade_level = $_POST['grade_level'];
    $section = trim($_POST['section']);
    $school_year = !empty(trim($_POST['school_year'])) ? trim($_POST['school_year']) : $currentSchoolYear;
    $capacity = (int)$_POST['capacity'];
    
    // Check for duplicate
    $check = $conn->prepare("SELECT id FROM classes WHERE grade_level = ? AND section = ? AND school_year = ?");
    $check->bind_param("sss", $grade_level, $section, $school_year);
    $check->execute();
    $check->store_result();
    
    if ($check->num_rows > 0) {
        $error = "This grade level and section already exists for this school year.";
    } else {
        $stmt = $conn->prepare("INSERT INTO classes (grade_level, section, school_year, capacity, status) VALUES (?, ?, ?, ?, 'Active')");
        $stmt->bind_param("sssi", $grade_level, $section, $school_year, $capacity);
        
        if ($stmt->execute()) {
            $class_id = $conn->insert_id;
            logActivity($conn, $user['id'], 'INSERT', 'classes', $class_id, 
                       "Added new class: Grade $grade_level - $section (SY: $school_year)");
            $_SESSION['success_message'] = "Class added successfully!";
            header("Location: classes.php");
            exit;
        } else {
            $error = "Error adding class: " . $conn->error;
        }
    }
}

include '../templates/header.php';
$current_page = 'classes';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-plus-circle-fill"></i> Add New Class</h2>
        <p class="subtitle">Create a new class section</p>
    </div>
    <a href="classes.php" class="btn btn-info">
        <i class="bi bi-arrow-left"></i> Back to Classes
    </a>
</div>

<?php if (!empty($error)): ?>
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

<!-- Add Class Form -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-collection"></i> Class Information
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                    <select name="grade_level" class="form-select" required>
                        <option value="">-- Select Grade Level --</option>
                        <?php for($i = 1; $i <= 6; $i++): ?>
                        <option value="<?= $i ?>">Grade <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Section Name <span class="text-danger">*</span></label>
                    <input type="text" name="section" class="form-control" placeholder="e.g., Diamond, Einstein, A" required>
                    <small class="text-muted">Enter section name (e.g., Diamond, A, Einstein)</small>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">School Year <span class="text-danger">*</span></label>
                    <input type="text" name="school_year" class="form-control" value="<?= htmlspecialchars($currentSchoolYear) ?>" placeholder="e.g., 2025-2026" required>
                    <small class="text-muted">Defaults to current school year — can be changed (e.g., 2025-2026)</small>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Maximum Capacity <span class="text-danger">*</span></label>
                    <input type="number" name="capacity" class="form-control" value="40" min="1" max="100" required>
                    <small class="text-muted">Maximum number of students</small>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="classes.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" name="add_class" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Add Class
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
