<?php
session_start();
include "../includes/db.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];
if ($user['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// Set current page for sidebar navigation
$_SERVER['PHP_SELF'] = 'users.php';

// Get user ID from URL
if (!isset($_GET['id'])) {
    header("Location: users.php");
    exit;
}

$user_id = (int)$_GET['id'];

// Fetch user details
$user_query = $conn->prepare("SELECT * FROM users WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();

if ($user_result->num_rows === 0) {
    header("Location: users.php");
    exit;
}

$view_user = $user_result->fetch_assoc();

// Get adviser assignment
$adviser = null;
$school_year_id = $_SESSION['school_year_id'];
$adviser_query = $conn->prepare("SELECT grade_level, section FROM teacher_assignments WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ?");
$adviser_query->bind_param("ii", $user_id, $school_year_id);
$adviser_query->execute();
$adviser_result = $adviser_query->get_result();
if ($adviser_result->num_rows > 0) {
    $adviser = $adviser_result->fetch_assoc();
}

// Get subject assignments with customized names
$subjects = [];
$subjects_query = $conn->prepare("
    SELECT 
        ta.subject_id,
        ta.grade_level,
        GROUP_CONCAT(ta.section ORDER BY ta.section) as sections,
        COALESCE(sgg.subject_name, s.subject_name) as subject_name
    FROM teacher_assignments ta
    JOIN subjects s ON ta.subject_id = s.id
    LEFT JOIN subject_grade_groups sgg ON s.id = sgg.subject_id AND ta.grade_level = sgg.grade_level
    WHERE ta.teacher_id = ? AND ta.assignment_type = 'subject' AND ta.school_year_id = ?
    GROUP BY ta.subject_id, ta.grade_level, sgg.subject_name, s.subject_name
    ORDER BY ta.grade_level, s.subject_name
");
$subjects_query->bind_param("ii", $user_id, $school_year_id);
$subjects_query->execute();
$subjects_result = $subjects_query->get_result();
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[] = $row;
}

include "../templates/header.php";
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-person-badge"></i> User Details</h2>
        <p class="subtitle">View teacher assignments and information</p>
    </div>
    <a href="users.php" class="btn btn-info">
        <i class="bi bi-arrow-left"></i> Back to Users
    </a>
</div>

<!-- User Information Card -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-person-circle"></i> User Information
    </div>
    <div class="card-body">
        <!-- Basic Info -->
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label text-muted">Full Name</label>
                <p class="form-control-plaintext fw-bold"><?= htmlspecialchars(strtoupper($view_user['full_name'])) ?></p>
            </div>
            <div class="col-md-6">
                <label class="form-label text-muted">Username</label>
                <p class="form-control-plaintext fw-bold"><?= htmlspecialchars($view_user['username']) ?></p>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label text-muted">Role</label>
                <p class="form-control-plaintext">
                    <?php if ($view_user['role'] === 'admin'): ?>
                        <span class="badge bg-danger fs-6">Admin</span>
                    <?php else: ?>
                        <span class="badge bg-primary fs-6">Teacher</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-6">
                <label class="form-label text-muted">Account Created</label>
                <p class="form-control-plaintext"><?= date('F d, Y', strtotime($view_user['created_at'])) ?></p>
            </div>
        </div>
        
        <!-- School Information -->
        <?php if (!empty($view_user['school_name']) || !empty($view_user['school_id']) || !empty($view_user['district']) || !empty($view_user['division']) || !empty($view_user['region'])): ?>
        <hr>
        <h6 class="mb-3"><i class="bi bi-building"></i> School Information</h6>
        <div class="row mb-3">
            <?php if (!empty($view_user['school_name'])): ?>
            <div class="col-md-8">
                <label class="form-label text-muted">School Name</label>
                <p class="form-control-plaintext"><?= htmlspecialchars($view_user['school_name']) ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($view_user['school_id'])): ?>
            <div class="col-md-4">
                <label class="form-label text-muted">School ID</label>
                <p class="form-control-plaintext"><?= htmlspecialchars($view_user['school_id']) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <div class="row mb-3">
            <?php if (!empty($view_user['district'])): ?>
            <div class="col-md-4">
                <label class="form-label text-muted">District</label>
                <p class="form-control-plaintext"><?= htmlspecialchars($view_user['district']) ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($view_user['division'])): ?>
            <div class="col-md-4">
                <label class="form-label text-muted">Division</label>
                <p class="form-control-plaintext"><?= htmlspecialchars($view_user['division']) ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($view_user['region'])): ?>
            <div class="col-md-4">
                <label class="form-label text-muted">Region</label>
                <p class="form-control-plaintext"><?= htmlspecialchars($view_user['region']) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($view_user['role'] === 'teacher'): ?>
        <!-- Teacher Assignments -->
        <hr>
        <h6 class="mb-3"><i class="bi bi-person-badge"></i> Teacher Assignments</h6>
        
        <!-- Class Adviser -->
        <div class="mb-3">
            <label class="form-label text-muted">Class Adviser Assignment</label>
            <?php if ($adviser): ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-bookmark-star me-2"></i>
                    <strong>Grade <?= $adviser['grade_level'] ?> - <?= htmlspecialchars($adviser['section']) ?></strong>
                </div>
            <?php else: ?>
                <p class="form-control-plaintext text-muted">No adviser assignment</p>
            <?php endif; ?>
        </div>
        
        <!-- Subject Assignments -->
        <div class="mb-3">
            <label class="form-label text-muted">Subject Assignments</label>
            <?php if (count($subjects) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Subject</th>
                                <th>Grade Level</th>
                                <th>Sections</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subj): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($subj['subject_name']) ?></strong></td>
                                <td><span class="badge bg-primary">Grade <?= $subj['grade_level'] ?></span></td>
                                <td><?= htmlspecialchars($subj['sections']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="form-control-plaintext text-muted">No subject assignments</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <hr>
        <div class="d-flex gap-2 justify-content-end">
            <a href="users.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back to Users
            </a>
            <a href="edit_user.php?id=<?= $user_id ?>" class="btn btn-warning">
                <i class="bi bi-pencil"></i> Edit User
            </a>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
