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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = "Invalid class ID.";
    header("Location: classes.php");
    exit();
}

// Fetch class record
$stmt = $conn->prepare("SELECT * FROM classes WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();

if (!$class) {
    $_SESSION['error_message'] = "Class not found.";
    header("Location: classes.php");
    exit();
}

// The class should always stay in its original school year when edited
$school_year = $class['school_year'];

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_class'])) {
    $grade_level = $_POST['grade_level'];
    $section = trim($_POST['section']);
    $capacity = (int)$_POST['capacity'];
    $status = $_POST['status'];

    // Check duplicate excluding current class
    $check = $conn->prepare("SELECT id FROM classes WHERE grade_level = ? AND section = ? AND school_year = ? AND id != ?");
    $check->bind_param("sssi", $grade_level, $section, $school_year, $id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $error = "This grade level and section already exists for this school year.";
    } else {
        $update = $conn->prepare("UPDATE classes SET grade_level = ?, section = ?, school_year = ?, capacity = ?, status = ? WHERE id = ?");
        $update->bind_param("sssisi", $grade_level, $section, $school_year, $capacity, $status, $id);

        if ($update->execute()) {
            logActivity(
                $conn,
                $user['id'],
                'UPDATE',
                'classes',
                $id,
                "Updated class: Grade $grade_level - $section (SY: $school_year)"
            );

            $_SESSION['success_message'] = "Class updated successfully!";
            header("Location: classes.php");
            exit();
        } else {
            $error = "Error updating class: " . $conn->error;
        }
    }

    // Refresh displayed values when validation fails
    $class['grade_level'] = $grade_level;
    $class['section'] = $section;
    $class['capacity'] = $capacity;
    $class['status'] = $status;
}

include '../templates/header.php';
$current_page = 'classes';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-pencil-square"></i> Edit Class</h2>
        <p class="subtitle">Update class information</p>
    </div>
    <a href="classes.php" class="btn btn-info">
        <i class="bi bi-arrow-left"></i> Back to Classes
    </a>
</div>

<?php if (!empty($error)): ?>
    <div class='alert alert-danger alert-dismissible fade show' role='alert' id='errorAlert'>
        <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

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
                            <option value="<?= $i ?>" <?= (string)$class['grade_level'] === (string)$i ? 'selected' : '' ?>>Grade <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Section Name <span class="text-danger">*</span></label>
                    <input type="text" name="section" class="form-control" value="<?= htmlspecialchars($class['section']) ?>" placeholder="e.g., Diamond, Einstein, A" required>
                    <small class="text-muted">Enter section name (e.g., Diamond, A, Einstein)</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">School Year <span class="text-danger">*</span></label>
                    <input type="text" name="school_year" class="form-control" value="<?= htmlspecialchars($class['school_year']) ?>" readonly>
                    <small class="text-muted">Auto-based on currently selected school year</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Maximum Capacity <span class="text-danger">*</span></label>
                    <input type="number" name="capacity" class="form-control" value="<?= (int)$class['capacity'] ?>" min="1" max="100" required>
                    <small class="text-muted">Maximum number of students</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="Active" <?= $class['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $class['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="classes.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" name="update_class" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Update Class
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
