<?php
require_once '../includes/db.php';
include '../templates/header.php';

// Admin only access
if (!$is_admin) {
    header("Location: dashboard.php");
    exit();
}

// Handle add subject
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_subject'])) {
    $subject_code = $_POST['subject_code'];
    $subject_name = $_POST['subject_name'];
    $description = $_POST['description'];
    
    $stmt = $conn->prepare("INSERT INTO subjects (subject_code, subject_name, description) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $subject_code, $subject_name, $description);
    
    if ($stmt->execute()) {
        $success_message = "Subject added successfully!";
    } else {
        $error_message = "Error adding subject: " . $conn->error;
    }
}

// Fetch all subjects
$subjects_query = "SELECT * FROM subjects ORDER BY subject_name";
$subjects_result = $conn->query($subjects_query);
?>

<div class="page-header">
    <h2><i class="bi bi-book"></i> Subjects Management</h2>
    <p class="subtitle">Manage all subjects in the system</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle"></i> <?= $success_message ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle"></i> <?= $error_message ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <!-- Add Subject Form -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-plus-circle"></i> Add New Subject
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Subject Code</label>
                        <input type="text" class="form-control" name="subject_code" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject Name</label>
                        <input type="text" class="form-control" name="subject_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <button type="submit" name="add_subject" class="btn btn-primary w-100">
                        <i class="bi bi-plus-lg"></i> Add Subject
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Subjects List -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-list-ul"></i> All Subjects
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($subjects_result && $subjects_result->num_rows > 0): ?>
                                <?php while ($subject = $subjects_result->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($subject['subject_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                    <td><?= htmlspecialchars($subject['description'] ?? '-') ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No subjects found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
