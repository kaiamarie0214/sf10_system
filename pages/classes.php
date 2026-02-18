<?php
require_once '../includes/db.php';
require_once '../includes/logger.php';
include '../templates/header.php';

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
    
    // Check if class is assigned to any teacher
    $check = $conn->prepare("SELECT id FROM users WHERE grade_level = (SELECT grade_level FROM classes WHERE id = ?) AND section = (SELECT section FROM classes WHERE id = ?)");
    $check->bind_param("ii", $id, $id);
    $check->execute();
    $check->store_result();
    
    if ($check->num_rows > 0) {
        $error = "Cannot delete this class. It is currently assigned to a teacher.";
    } else {
        // Get class info before deleting
        $class_info = $conn->query("SELECT grade_level, section, school_year FROM classes WHERE id = $id")->fetch_assoc();
        
        $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            logActivity($conn, $user['id'], 'DELETE', 'classes', $id, 
                       "Deleted class: Grade {$class_info['grade_level']} - {$class_info['section']} (SY: {$class_info['school_year']})");
            $success = "Class deleted successfully!";
        } else {
            $error = "Error deleting class: " . $conn->error;
        }
    }
}

// Filter
$filter_grade = isset($_GET['filter_grade']) ? $_GET['filter_grade'] : 'all';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'all';
$filter_sy = isset($_GET['filter_sy']) ? $_GET['filter_sy'] : 'all';

// Build query
$where = [];
if ($filter_grade !== 'all') {
    $where[] = "grade_level = '$filter_grade'";
}
if ($filter_status !== 'all') {
    $where[] = "status = '$filter_status'";
}
if ($filter_sy !== 'all') {
    $where[] = "school_year = '$filter_sy'";
}
$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get classes with student count
$classes_query = "SELECT c.*, 
                  (SELECT COUNT(*) FROM students WHERE grade_level = c.grade_level AND section = c.section) as student_count
                  FROM classes c 
                  $where_sql
                  ORDER BY c.grade_level, c.section";
$classes = $conn->query($classes_query);

// Get distinct school years for filter
$school_years = $conn->query("SELECT DISTINCT school_year FROM classes ORDER BY school_year DESC");

// Get statistics
$total_classes = $conn->query("SELECT COUNT(*) as count FROM classes")->fetch_assoc()['count'];
$active_classes = $conn->query("SELECT COUNT(*) as count FROM classes WHERE status = 'Active'")->fetch_assoc()['count'];
$total_students = $conn->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
$total_teachers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'")->fetch_assoc()['count'];

$current_page = 'classes';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-collection"></i> All Classes</h2>
        <p class="subtitle">Manage classes to assign to teachers</p>
    </div>
    <a href="add_class.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Add New Class
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
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-list-ul"></i> All Classes 
            <span class="badge bg-primary ms-2"><?= number_format($total_classes) ?> classes</span>
        </span>
        <div class="d-flex gap-2">
            <select id="sortClasses" class="form-select form-select-sm" style="width: auto;">
                        <option value="grade-asc">Grade Level (1-6)</option>
                        <option value="grade-desc">Grade Level (6-1)</option>
                        <option value="section-asc">Section (A-Z)</option>
                        <option value="section-desc">Section (Z-A)</option>
                    </select>
                    <div style="position: relative; width: 250px;">
                        <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
                        <input type="text" class="form-control form-control-sm" id="classSearch" placeholder="Search by grade or section..." style="padding-left: 35px; padding-right: 30px;">
                        <button type="button" id="clearClassSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; display: none;">
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
                    overflow: hidden !important;
                    height: 100vh;
                    margin: 0;
                    padding: 0;
                }
                body {
                    display: flex;
                    flex-direction: column;
                }
                #mainContent {
                    display: flex;
                    flex-direction: column;
                    flex: 1 1 auto;
                    overflow: hidden;
                    padding-bottom: 0 !important;
                }
                footer {
                    flex-shrink: 0;
                    position: sticky;
                    bottom: 0;
                    z-index: 100;
                }
                #mainContent > * {
                    flex-shrink: 0;
                }
                #mainContent .card:last-of-type {
                    flex: 1 1 auto;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                    overflow: hidden;
                    margin-bottom: 0 !important;
                }
                .card-body {
                    flex: 1 1 auto;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                    overflow: hidden;
                    padding-bottom: 1rem !important;
                }
                .table-responsive {
                    flex: 1 1 auto;
                    min-height: 0;
                    overflow-y: auto !important;
                    overflow-x: hidden;
                    margin-bottom: 0;
                }
                #classesTable {
                    font-size: 13px;
                    width: 100%;
                    margin-bottom: 0;
                }
                #classesTable th, #classesTable td {
                    padding: 6px 8px;
                }
                #classesTable thead {
                    position: sticky;
                    top: 0;
                    z-index: 10;
                    background: var(--card-bg, #fff);
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
                    <table class="table table-hover" id="classesTable">
                        <thead style="position: sticky; top: 0; z-index: 10;">
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
                                                    <i class="bi bi-pencil text-warning me-2"></i>Edit Class
                                                </a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="confirmDelete(<?= $class['id'] ?>, 'Grade <?= $class['grade_level'] ?> - <?= htmlspecialchars($class['section']) ?>')">
                                                    <i class="bi bi-trash me-2"></i>Delete Class
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
    const tableBody = document.querySelector('#classesTable tbody');
    const classRows = Array.from(tableBody.querySelectorAll('.class-row'));
    
    if (searchInput && clearSearchBtn) {
        // Show/hide clear button based on input value
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            clearSearchBtn.style.display = this.value ? 'block' : 'none';
            
            classRows.forEach(row => {
                const grade = row.getAttribute('data-grade').toLowerCase();
                const section = row.getAttribute('data-section').toLowerCase();
                
                const matches = grade.includes(searchTerm) || section.includes(searchTerm);
                row.style.display = matches ? '' : 'none';
            });
        });
        
        // Clear search
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            this.style.display = 'none';
            classRows.forEach(row => row.style.display = '');
            searchInput.focus();
        });
    }
    
    // Sort functionality
    if (sortSelect) {
        sortSelect.addEventListener('change', function() {
            const sortValue = this.value;
            
            classRows.sort((a, b) => {
                let aVal, bVal;
                
                if (sortValue.startsWith('grade-')) {
                    aVal = parseInt(a.getAttribute('data-grade'));
                    bVal = parseInt(b.getAttribute('data-grade'));
                } else if (sortValue.startsWith('section-')) {
                    aVal = a.getAttribute('data-section').toLowerCase();
                    bVal = b.getAttribute('data-section').toLowerCase();
                }
                
                if (sortValue.endsWith('-asc')) {
                    return aVal > bVal ? 1 : -1;
                } else {
                    return aVal < bVal ? 1 : -1;
                }
            });
            
            // Re-append sorted rows
            classRows.forEach(row => tableBody.appendChild(row));
        });
    }
})();
</script>

<?php include '../templates/footer.php'; ?>
