<?php
require_once '../includes/db.php';
require_once '../includes/logger.php';

// Admin only access check (before output)
session_start();
$user = $_SESSION['user'] ?? null;
$is_admin = $user && $user['role'] === 'admin';

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
    $school_year = $currentSchoolYear;
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
            $success = "Class added successfully!";
        } else {
            $error = "Error adding class: " . $conn->error;
        }
    }
}

// Handle Edit Class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_class'])) {
    $id = (int)$_POST['class_id'];
    $grade_level = $_POST['grade_level'];
    $section = trim($_POST['section']);
    $school_year = $currentSchoolYear;
    $capacity = (int)$_POST['capacity'];
    $status = $_POST['status'];
    
    // Check for duplicate (excluding current record)
    $check = $conn->prepare("SELECT id FROM classes WHERE grade_level = ? AND section = ? AND school_year = ? AND id != ?");
    $check->bind_param("sssi", $grade_level, $section, $school_year, $id);
    $check->execute();
    $check->store_result();
    
    if ($check->num_rows > 0) {
        $error = "This grade level and section already exists for this school year.";
    } else {
        $stmt = $conn->prepare("UPDATE classes SET grade_level = ?, section = ?, school_year = ?, capacity = ?, status = ? WHERE id = ?");
        $stmt->bind_param("sssisi", $grade_level, $section, $school_year, $capacity, $status, $id);
        
        if ($stmt->execute()) {
            logActivity($conn, $user['id'], 'UPDATE', 'classes', $id, 
                       "Updated class: Grade $grade_level - $section (SY: $school_year)");
            $success = "Class updated successfully!";
        } else {
            $error = "Error updating class: " . $conn->error;
        }
    }
}

// Handle Delete Class
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    
    // Get class info before deleting
    $class_info = $conn->query("SELECT grade_level, section, school_year FROM classes WHERE id = $id")->fetch_assoc();
    
    if (!$class_info) {
        // Class already deleted or invalid ID, redirect to clear URL
        header("Location: classes.php");
        exit();
    }
    
    // Check if class is assigned to any teacher (adviser or subject teacher)
    $check = $conn->prepare("SELECT id FROM teacher_assignments 
                             WHERE grade_level = ? AND section = ? AND school_year = ?");
    $check->bind_param("sss", $class_info['grade_level'], $class_info['section'], $class_info['school_year']);
    $check->execute();
    $check->store_result();
    
    if ($check->num_rows > 0) {
        $error = "Cannot delete this class. It is currently assigned to a teacher.";
    } else {
        $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity($conn, $user['id'], 'DELETE', 'classes', $id, 
                       "Deleted class: Grade {$class_info['grade_level']} - {$class_info['section']} (SY: {$class_info['school_year']})");
            $_SESSION['success_message'] = "Class deleted successfully!";
            header("Location: classes.php");
            exit();
        } else {
            $error = "Error deleting class: " . $conn->error;
        }
    }
}

// Handle Carry Forward Classes from Previous Year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['carry_forward'])) {
    // 1. Get the previous school year (the one before the currently selected one)
    $prev_sy_query = $conn->prepare("SELECT year FROM school_years WHERE year < ? ORDER BY year DESC LIMIT 1");
    $prev_sy_query->bind_param("s", $currentSchoolYear);
    $prev_sy_query->execute();
    $prev_sy_res = $prev_sy_query->get_result();
    
    if ($prev_sy_res->num_rows === 0) {
        $error = "No previous school year found to copy classes from.";
    } else {
        $prev_school_year = $prev_sy_res->fetch_assoc()['year'];
        
        // 2. Fetch all active classes from the previous year
        $prev_classes_query = $conn->prepare("SELECT grade_level, section, capacity, status FROM classes WHERE school_year = ? AND status = 'Active'");
        $prev_classes_query->bind_param("s", $prev_school_year);
        $prev_classes_query->execute();
        $prev_classes = $prev_classes_query->get_result();
        
        if ($prev_classes->num_rows === 0) {
            $error = "No active classes found in SY $prev_school_year to carry forward.";
        } else {
            $count = 0;
            $skipped = 0;
            
            // 3. Duplicate them for the current year
            while ($p_class = $prev_classes->fetch_assoc()) {
                // Check if already exists in current year
                $check = $conn->prepare("SELECT id FROM classes WHERE grade_level = ? AND section = ? AND school_year = ?");
                $check->bind_param("sss", $p_class['grade_level'], $p_class['section'], $currentSchoolYear);
                $check->execute();
                if ($check->get_result()->num_rows === 0) {
                    $insert = $conn->prepare("INSERT INTO classes (grade_level, section, school_year, capacity, status) VALUES (?, ?, ?, ?, ?)");
                    $insert->bind_param("sssis", $p_class['grade_level'], $p_class['section'], $currentSchoolYear, $p_class['capacity'], $p_class['status']);
                    if ($insert->execute()) {
                        $count++;
                    }
                } else {
                    $skipped++;
                }
            }
            
            if ($count > 0) {
                logActivity($conn, $user['id'], 'IMPORT', 'classes', null, "Carried forward $count classes from SY $prev_school_year to SY $currentSchoolYear");
                $success = "Successfully copied $count classes from SY $prev_school_year. ($skipped already existed)";
            } else if ($skipped > 0) {
                $error = "All classes from SY $prev_school_year already exist in the current school year.";
            } else {
                $error = "Failed to copy classes.";
            }
        }
    }
}

// Filter
$filter_grade = isset($_GET['filter_grade']) ? $_GET['filter_grade'] : 'all';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'all';
$filter_sy = isset($_GET['filter_sy']) ? $_GET['filter_sy'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'grade-asc';

// Build query
$where = [];
// Automatically filter by the school year selected in the header
$where[] = "school_year = '" . $conn->real_escape_string($currentSchoolYear) . "'";

if ($filter_grade !== 'all') {
    $where[] = "grade_level = '$filter_grade'";
}
if ($filter_status !== 'all') {
    $where[] = "status = '$filter_status'";
}
// Note: $filter_sy is kept for backward compatibility but currentSchoolYear takes precedence for default view
if ($filter_sy !== 'all') {
    // If a specific year is chosen in the filter dropdown, it overrides the header year
    $where[0] = "school_year = '$filter_sy'";
}
if (!empty($search)) {
    $search_terms = preg_split('/\s+/', trim($search));
    foreach ($search_terms as $term) {
        $term_escaped = $conn->real_escape_string($term);
        // Support searching by "Grade X", just the number, or section name
        $where[] = "(grade_level LIKE '%$term_escaped%' 
                   OR section LIKE '%$term_escaped%' 
                   OR CONCAT('Grade ', grade_level) LIKE '%$term_escaped%')";
    }
}
$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Sort logic
$order_by = "";
if (!empty($search)) {
    $search_exact = $conn->real_escape_string(trim($search));
    $order_by = "CASE 
        WHEN grade_level = '$search_exact' THEN 0
        WHEN section = '$search_exact' THEN 1
        WHEN grade_level LIKE '$search_exact%' THEN 2
        WHEN section LIKE '$search_exact%' THEN 3
        ELSE 4 
    END ASC, ";
}

switch ($sort) {
    case 'grade-asc': $order_by .= "c.grade_level ASC, c.section ASC"; break;
    case 'grade-desc': $order_by .= "c.grade_level DESC, c.section ASC"; break;
    case 'section-asc': $order_by .= "c.section ASC, c.grade_level ASC"; break;
    case 'section-desc': $order_by .= "c.section DESC, c.grade_level ASC"; break;
    default: $order_by .= "c.grade_level ASC, c.section ASC";
}

// --- PAGINATION LOGIC ---
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = 20;

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM classes $where_sql";
$total_classes_filtered = $conn->query($count_query)->fetch_assoc()['total'];
$total_pages = ceil($total_classes_filtered / $items_per_page);
$page = max(1, min($total_pages, $page));
$offset = ($page - 1) * $items_per_page;

// Get classes with student count
$classes_query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM students WHERE grade_level = c.grade_level AND section = c.section) as student_count
                  FROM classes c 
                  $where_sql
                  ORDER BY $order_by
                  LIMIT $items_per_page OFFSET $offset";
$classes = $conn->query($classes_query);

// Get distinct school years for filter
$school_years = $conn->query("SELECT DISTINCT school_year FROM classes ORDER BY school_year DESC");

// Get statistics
$stats_where = "WHERE school_year = '" . $conn->real_escape_string($currentSchoolYear) . "'";
$total_classes = $conn->query("SELECT COUNT(*) as count FROM classes $stats_where")->fetch_assoc()['count'];
$active_classes = $conn->query("SELECT COUNT(*) as count FROM classes $stats_where AND status = 'Active'")->fetch_assoc()['count'];
$total_students = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
$total_teachers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'")->fetch_assoc()['count'];

$current_page = 'classes';
include '../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-collection"></i> All Classes</h2>
        <p class="subtitle">Manage classes to assign to teachers</p>
    </div>
    <div class="d-flex gap-2">
        <?php 
        // Show copy button if no classes in current filter/year AND there are classes in the previous year
        $has_previous_classes = false;
        if ((int)$total_classes_filtered === 0 && !empty($currentSchoolYear)) {
            $prev_check = $conn->prepare("SELECT COUNT(*) as count FROM classes WHERE school_year < ?");
            $prev_check->bind_param("s", $currentSchoolYear);
            $prev_check->execute();
            $has_previous_classes = ($prev_check->get_result()->fetch_assoc()['count'] > 0);
        }

        if ($has_previous_classes): 
        ?>
            <form method="POST" onsubmit="return confirm('Copy all active classes from the previous school year to SY <?= $currentSchoolYear ?>?')">
                <button type="submit" name="carry_forward" class="btn btn-outline-info">
                    <i class="bi bi-arrow-repeat"></i> Copy Classes from Previous Year
                </button>
            </form>
        <?php endif; ?>
        <a href="add_class.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Add New Class
        </a>
    </div>
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

<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert-success alert-dismissible fade show" id="sessionSuccessAlert">
    <i class="bi bi-check-circle"></i> <?= $_SESSION['success_message'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<script>
// Auto-dismiss alerts with fade out
document.addEventListener('DOMContentLoaded', function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    const sessionSuccessAlert = document.getElementById('sessionSuccessAlert');
    
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.transition = 'opacity 0.5s ease-out';
            successAlert.style.opacity = '0';
            setTimeout(() => successAlert.remove(), 500);
        }, 5000);
    }
    
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.transition = 'opacity 0.5s ease-out';
            errorAlert.style.opacity = '0';
            setTimeout(() => errorAlert.remove(), 500);
        }, 7000);
    }
    
    if (sessionSuccessAlert) {
        setTimeout(() => {
            sessionSuccessAlert.style.transition = 'opacity 0.5s ease-out';
            sessionSuccessAlert.style.opacity = '0';
            setTimeout(() => sessionSuccessAlert.remove(), 500);
        }, 5000);
    }
});
</script>

<!-- Classes List -->
<div class="card classes-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-list-ul"></i> All Classes 
            <span class="badge bg-primary ms-2"><?= number_format($total_classes) ?> classes</span>
        </span>
        <div class="d-flex gap-2">
            <select id="sortClasses" class="form-select form-select-sm" style="width: auto;">
                        <option value="grade-asc" <?= $sort == 'grade-asc' ? 'selected' : '' ?>>Grade Level (1-6)</option>
                        <option value="grade-desc" <?= $sort == 'grade-desc' ? 'selected' : '' ?>>Grade Level (6-1)</option>
                        <option value="section-asc" <?= $sort == 'section-asc' ? 'selected' : '' ?>>Section (A-Z)</option>
                        <option value="section-desc" <?= $sort == 'section-desc' ? 'selected' : '' ?>>Section (Z-A)</option>
                    </select>
                    <div style="position: relative; width: 250px;">
                        <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
                        <input type="text" class="form-control form-control-sm" id="classSearch" placeholder="Search by grade or section..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 35px; padding-right: 30px;">
                        <button type="button" id="clearClassSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; <?= empty($search) ? 'display: none;' : 'display: block;' ?>">
                            <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
                        </button>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#filterPanel">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                </div>
            </div>
            
            <!-- Filter Panel -->
            <div class="collapse" id="filterPanel">
                <div class="card-body border-bottom">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Grade Level</label>
                            <select name="filter_grade" class="form-select form-select-sm">
                                <option value="all" <?= $filter_grade == 'all' ? 'selected' : '' ?>>All Grades</option>
                                <?php for($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>" <?= $filter_grade == $i ? 'selected' : '' ?>>Grade <?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="filter_status" class="form-select form-select-sm">
                                <option value="all" <?= $filter_status == 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="Active" <?= $filter_status == 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= $filter_status == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">School Year</label>
                            <select name="filter_sy" class="form-select form-select-sm">
                                <option value="all" <?= $filter_sy == 'all' ? 'selected' : '' ?>>All Years</option>
                                <?php while($sy = $school_years->fetch_assoc()): ?>
                                <option value="<?= $sy['school_year'] ?>" <?= $filter_sy == $sy['school_year'] ? 'selected' : '' ?>><?= $sy['school_year'] ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-search"></i> Apply Filter
                            </button>
                            <a href="classes.php" class="btn btn-sm btn-secondary">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <style>
                html, body {
                    height: 100vh;
                    margin: 0;
                    padding: 0;
                    overflow: hidden !important;
                }
                body {
                    display: flex;
                    flex-direction: column;
                }
                .main-wrapper {
                    flex: 1 1 auto;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                    overflow: hidden;
                }
                footer {
                    flex-shrink: 0;
                }
                .classes-card {
                    flex: 1 1 auto;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                    overflow: hidden;
                    margin-bottom: 0 !important;
                }
                .classes-card .card-body {
                    flex: 1 1 auto;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                    overflow: hidden;
                    padding: 0;
                }
                .table-responsive {
                    flex: 1 1 auto;
                    min-height: 0;
                    overflow-y: auto !important;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    margin-bottom: 0;
                }
                #classesTable {
                    font-size: 13px;
                    width: 100%;
                    margin-bottom: 0;
                }
                #classesTable th, #classesTable td {
                    padding: 8px;
                }
                #classesTable thead {
                    position: sticky;
                    top: 0;
                    z-index: 10;
                    background: var(--card-bg, #fff);
                }

                .pagination-container {
                    flex-shrink: 0;
                    position: sticky;
                    bottom: 0;
                    z-index: 1000;
                    background: var(--card-bg);
                    border-top: 2px solid var(--border-color);
                    padding: 12px 15px;
                    box-shadow: 0 -2px 8px rgba(0,0,0,0.1);
                }

                body.dark-theme .pagination-container {
                    background: #242526;
                    border-top-color: #3a3b3c;
                    box-shadow: 0 -2px 8px rgba(0,0,0,0.3);
                }

                /* Mobile pagination adjustments */
                @media (max-width: 768px) {
                    .pagination-container {
                        padding: 10px;
                    }
                    
                    .pagination-container .d-flex {
                        flex-direction: column;
                        gap: 10px;
                    }
                    
                    .pagination-container nav {
                        width: 100%;
                        overflow-x: auto;
                    }
                    
                    .pagination-container .pagination {
                        flex-wrap: nowrap;
                        justify-content: center;
                    }
                    
                    .pagination-container .page-item .page-link {
                        padding: 6px 10px;
                        font-size: 14px;
                    }
                    
                    .pagination-container .text-muted {
                        text-align: center;
                        font-size: 13px;
                    }
                    
                    /* Hide page jump on very small screens */
                    .pagination-container form {
                        display: none;
                    }
                }

                /* Mobile responsive styles */
                @media (max-width: 768px) {
                    .card-header {
                        flex-direction: column !important;
                        align-items: stretch !important;
                        gap: 0.75rem;
                    }
                    
                    .card-header .d-flex.gap-2 {
                        flex-direction: column;
                        width: 100%;
                        gap: 0.5rem !important;
                    }
                    
                    .card-header .d-flex.gap-2 > div {
                        width: 100% !important;
                    }
                    
                    .card-header .d-flex.gap-2 > select {
                        width: 100% !important;
                    }
                    
                    .card-header .d-flex.gap-2 > button {
                        width: 100% !important;
                    }
                    
                    #sortClasses {
                        width: 100% !important;
                    }
                    
                    .table-responsive {
                        overflow-x: auto !important;
                        -webkit-overflow-scrolling: touch;
                    }
                    
                    #classesTable {
                        min-width: 700px;
                    }
                    
                    /* Fix dropdown overlay on mobile */
                    #classesTable td:last-child {
                        position: static !important;
                    }
                    
                    .dropdown-menu {
                        position: fixed !important;
                        z-index: 10000 !important;
                    }
                }
            </style>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="classesTable">
                        <thead>
                            <tr>
                                <th>Grade Level</th>
                                <th>Section</th>
                                <th>School Year</th>
                                <th>Capacity</th>
                                <th>Students</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($classes && $classes->num_rows > 0): ?>
                                <?php while ($class = $classes->fetch_assoc()): ?>
                                <tr class="class-row"
                                    data-grade="<?= $class['grade_level'] ?>"
                                    data-section="<?= htmlspecialchars($class['section']) ?>"
                                    data-status="<?= htmlspecialchars($class['status']) ?>">
                                    <td><strong>Grade <?= htmlspecialchars($class['grade_level']) ?></strong></td>
                                    <td><span class="badge bg-info"><?= htmlspecialchars($class['section']) ?></span></td>
                                    <td><small><?= htmlspecialchars($class['school_year']) ?></small></td>
                                    <td><?= $class['capacity'] ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?= $class['student_count'] ?> / <?= $class['capacity'] ?></span>
                                    </td>
                                    <td>
                                        <?php if ($class['status'] == 'Active'): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="dropdown" style="position: static;">
                                            <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" id="actionsDropdown<?= $class['id'] ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="actionsDropdown<?= $class['id'] ?>" style="z-index: 1050;">
                                                <li><a class="dropdown-item" href="edit_class.php?id=<?= $class['id'] ?>">
                                                    <i class="bi bi-pencil text-warning me-2"></i>Edit
                                                </a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= $class['id'] ?>, 'Grade <?= $class['grade_level'] ?> - <?= htmlspecialchars($class['section']) ?>')">
                                                    <i class="bi bi-trash me-2"></i>Delete
                                                </a></li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>

                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.3;"></i>
                                        <p class="mt-2">No classes found</p>
                                        <small>Add a class to get started</small>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_classes_filtered > 0): ?>
            <div class="pagination-container">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted">
                        Page <?= $page ?> of <?= max(1, $total_pages) ?>
                    </div>
                    
                    <nav aria-label="Page navigation">
                        <ul class="pagination mb-0">
                            <!-- First Page -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=1&filter_grade=<?= urlencode($filter_grade) ?>&filter_status=<?= urlencode($filter_status) ?>&filter_sy=<?= urlencode($filter_sy) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" title="First Page">
                                    <i class="bi bi-chevron-double-left"></i>
                                </a>
                            </li>
                            
                            <!-- Previous Page -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= max(1, $page - 1) ?>&filter_grade=<?= urlencode($filter_grade) ?>&filter_status=<?= urlencode($filter_status) ?>&filter_sy=<?= urlencode($filter_sy) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>">Previous</a>
                            </li>
                            
                            <!-- Page Numbers -->
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min(max(1, $total_pages), $start_page + 4);
                            $start_page = max(1, $end_page - 4);
                            
                            for($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&filter_grade=<?= urlencode($filter_grade) ?>&filter_status=<?= urlencode($filter_status) ?>&filter_sy=<?= urlencode($filter_sy) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>"><?= $i ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <!-- Next Page -->
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= min($total_pages, $page + 1) ?>&filter_grade=<?= urlencode($filter_grade) ?>&filter_status=<?= urlencode($filter_status) ?>&filter_sy=<?= urlencode($filter_sy) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>">Next</a>
                            </li>
                            
                            <!-- Last Page -->
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= max(1, $total_pages) ?>&filter_grade=<?= urlencode($filter_grade) ?>&filter_status=<?= urlencode($filter_status) ?>&filter_sy=<?= urlencode($filter_sy) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" title="Last Page">
                                    <i class="bi bi-chevron-double-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    
                    <!-- Custom Page Jump -->
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small">Go to:</span>
                        <form method="GET" class="d-flex gap-2" onsubmit="return validatePageJump()">
                            <input type="hidden" name="filter_grade" value="<?= htmlspecialchars($filter_grade) ?>">
                            <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filter_status) ?>">
                            <input type="hidden" name="filter_sy" value="<?= htmlspecialchars($filter_sy) ?>">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                            <input type="number" 
                                   name="page" 
                                   id="pageJump"
                                   class="form-control form-control-sm" 
                                   style="width: 70px;" 
                                   min="1" 
                                   max="<?= max(1, $total_pages) ?>"
                                   placeholder="<?= $page ?>"
                                   title="Enter page number (1-<?= max(1, $total_pages) ?>)">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-arrow-right"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <script>
                function validatePageJump() {
                    const input = document.getElementById('pageJump');
                    const value = parseInt(input.value);
                    const max = parseInt(input.max);
                    
                    if (!value || value < 1 || value > max) {
                        alert('Please enter a valid page number between 1 and ' + max);
                        return false;
                    }
                    return true;
                }
                </script>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteClassModal" tabindex="-1" style="margin-top: 80px;">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Confirm Delete</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete class <strong id="delete_class_name"></strong>?</p>
        <p class="text-danger"><i class="bi bi-info-circle"></i> This action cannot be undone. All associated records will be deleted.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete Class</button>
      </div>
    </div>
  </div>
</div>

<script>
// Delete confirmation
function confirmDelete(classId, className) {
  document.getElementById('delete_class_name').textContent = className;
  document.getElementById('confirmDeleteBtn').onclick = function() {
    window.location.href = '?delete_id=' + classId;
  };
  new bootstrap.Modal(document.getElementById('deleteClassModal')).show();
}

// Search and Sort functionality
(function() {
    const searchInput = document.getElementById('classSearch');
    const clearSearchBtn = document.getElementById('clearClassSearch');
    const sortSelect = document.getElementById('sortClasses');
    
    function updateTable() {
        const search = searchInput ? searchInput.value : '';
        const sort = sortSelect ? sortSelect.value : 'grade-asc';
        
        // Preserve other filters
        const urlParams = new URLSearchParams(window.location.search);
        const filterGrade = urlParams.get('filter_grade') || 'all';
        const filterStatus = urlParams.get('filter_status') || 'all';
        const filterSy = urlParams.get('filter_sy') || 'all';
        
        const url = new URL(window.location.href);
        url.searchParams.set('page', '1');
        url.searchParams.set('filter_grade', filterGrade);
        url.searchParams.set('filter_status', filterStatus);
        url.searchParams.set('filter_sy', filterSy);
        url.searchParams.set('search', search);
        url.searchParams.set('sort', sort);

        // Save focus and cursor position
        if (searchInput === document.activeElement) {
            sessionStorage.setItem('classSearchFocus', 'true');
            sessionStorage.setItem('classSearchCursor', searchInput.selectionStart);
        }
        
        window.location.href = url.toString();
    }

    // Restore focus and cursor position after reload
    window.addEventListener('load', function() {
        if (sessionStorage.getItem('classSearchFocus') === 'true' && searchInput) {
            searchInput.focus();
            const cursorPos = sessionStorage.getItem('classSearchCursor');
            if (cursorPos !== null) {
                searchInput.setSelectionRange(cursorPos, cursorPos);
            }
            sessionStorage.removeItem('classSearchFocus');
            sessionStorage.removeItem('classSearchCursor');
        }
    });

    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            if (clearSearchBtn) clearSearchBtn.style.display = this.value ? 'block' : 'none';
            
            // Use debounce for search to avoid too many reloads
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                updateTable();
            }, 800);
        });
        
        // Clear search
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                this.style.display = 'none';
                updateTable();
            });
        }
    }
    
    // Sort functionality
    if (sortSelect) {
        sortSelect.addEventListener('change', updateTable);
    }
})();

</script>

<?php include '../templates/footer.php'; ?>
