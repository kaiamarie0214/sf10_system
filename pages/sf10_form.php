<?php
require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];
if ($user['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// --- FILTERS & PAGINATION ---
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name-asc';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = 20;

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = "";

if (!empty($search)) {
    $where_conditions[] = "(s.last_name LIKE ? OR s.first_name LIKE ? OR s.lrn LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($sort === 'filter-male') {
    $where_conditions[] = "s.gender = 'Male'";
} elseif ($sort === 'filter-female') {
    $where_conditions[] = "s.gender = 'Female'";
}

$where_sql = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Build ORDER BY clause
$order_sql = "s.last_name, s.first_name";
switch ($sort) {
    case 'name-asc': $order_sql = "s.last_name ASC, s.first_name ASC"; break;
    case 'name-desc': $order_sql = "s.last_name DESC, s.first_name DESC"; break;
    case 'grade-asc': $order_sql = "sa.grade_level ASC, sa.section ASC"; break;
    case 'grade-desc': $order_sql = "sa.grade_level DESC, sa.section DESC"; break;
    case 'gender-male': $order_sql = "s.gender DESC, s.last_name ASC"; break;
    case 'gender-female': $order_sql = "s.gender ASC, s.last_name ASC"; break;
}

// Get total count for pagination
$count_query = "SELECT COUNT(DISTINCT s.id) as total 
                FROM students s 
                LEFT JOIN schools_attended sa ON s.id = sa.student_id
                AND sa.id = (
                    SELECT id FROM schools_attended 
                    WHERE student_id = s.id 
                    ORDER BY grade_level DESC, school_year DESC 
                    LIMIT 1
                )
                $where_sql";

$stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_students = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_students / $items_per_page);
$page = max(1, min($total_pages, $page));
$offset = ($page - 1) * $items_per_page;

// Get students with latest school record
$students_query = "SELECT s.id, s.first_name, s.last_name, s.middle_name, s.lrn, s.gender, s.birthdate,
                          sa.grade_level, sa.section
                   FROM students s
                   LEFT JOIN schools_attended sa ON s.id = sa.student_id
                   AND sa.id = (
                       SELECT id FROM schools_attended 
                       WHERE student_id = s.id 
                       ORDER BY grade_level DESC, school_year DESC 
                       LIMIT 1
                   )
                   $where_sql
                   ORDER BY $order_sql
                   LIMIT ? OFFSET ?";

$stmt = $conn->prepare($students_query);
$final_types = $types . "ii";
$final_params = array_merge($params, [$items_per_page, $offset]);
$stmt->bind_param($final_types, ...$final_params);
$stmt->execute();
$students = $stmt->get_result();

// Get all school years
$school_years_query = "SELECT DISTINCT school_year FROM schools_attended ORDER BY school_year DESC";
$school_years = $conn->query($school_years_query);

// Get all grade levels
$grade_levels = [1, 2, 3, 4, 5, 6];

$page_title = "Generate SF10 Form";
require_once '../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-file-earmark-spreadsheet"></i> Generate SF10 Form</h2>
        <p class="subtitle">Official DepEd SF10 Elementary 2017 Excel Template</p>
    </div>
</div>

<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show" id="errorAlert">
    <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($_GET['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show" id="successAlert">
    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_GET['success']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<script>
// Auto-dismiss alerts with fade out
document.addEventListener('DOMContentLoaded', function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
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
});
</script>

<style>
  /* Flexbox layout for scrollable table */
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
  #mainContent .card:last-of-type .card-body {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    padding: 0 !important;
  }
  #mainContent .card:last-of-type .table-responsive {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto !important;
    overflow-x: auto;
    margin-bottom: 0;
    -webkit-overflow-scrolling: touch;
  }
  #studentsTable {
    font-size: 13px;
    width: 100%;
    margin-bottom: 0;
    min-width: 700px;
  }
  #studentsTable th, #studentsTable td {
    padding: 8px;
  }
  #studentsTable thead {
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
</style>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-file-earmark-excel"></i> SF10 Form Generator
            <span class="badge bg-secondary ms-2" id="studentCount"><?= number_format($total_students) ?></span>
        </span>
        <div class="d-flex gap-2">
            <select id="sortStudents" class="form-select form-select-sm" style="width: auto;">
                <option value="name-asc" <?= $sort === 'name-asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                <option value="name-desc" <?= $sort === 'name-desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                <option value="grade-asc" <?= $sort === 'grade-asc' ? 'selected' : '' ?>>Grade Level (1-6)</option>
                <option value="grade-desc" <?= $sort === 'grade-desc' ? 'selected' : '' ?>>Grade Level (6-1)</option>
                <option value="gender-male" <?= $sort === 'gender-male' ? 'selected' : '' ?>>Gender (Male First)</option>
                <option value="gender-female" <?= $sort === 'gender-female' ? 'selected' : '' ?>>Gender (Female First)</option>
                <option value="filter-male" <?= $sort === 'filter-male' ? 'selected' : '' ?>>All Male</option>
                <option value="filter-female" <?= $sort === 'filter-female' ? 'selected' : '' ?>>All Female</option>
            </select>
            <div style="position: relative; width: 250px;">
                <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
                <input type="text" class="form-control form-control-sm" id="student_search" placeholder="Search by name or LRN..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 35px; padding-right: 30px;">
                <button type="button" id="clearStudentSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; <?= empty($search) ? 'display: none;' : 'display: block;' ?>">
                    <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch; position: relative;">
            <form action="sf10_preview.php" method="GET" id="sf10Form" style="display: none;">
                <input type="hidden" name="student_id" id="hidden_student_id">
            </form>
            
            <table class="table table-hover mb-0" id="studentsTable" style="min-width: 700px; position: relative;">
                <thead>
                    <tr>
                        <th>LRN</th>
                        <th>NAME</th>
                        <th>GENDER</th>
                        <th>BIRTHDATE</th>
                        <th>GRADE/SECTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($students->num_rows > 0): ?>
                        <?php while ($student = $students->fetch_assoc()): 
                            $fullName = strtoupper($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''));
                            $gradeSection = $student['grade_level'] 
                                ? $student['grade_level'] . ($student['section'] ? ' - ' . $student['section'] : '') 
                                : 'N/A';
                        ?>
                        <tr style="cursor: pointer;" 
                            onclick="previewStudent(<?= $student['id'] ?>)"
                            class="student-row">
                            <td><?= htmlspecialchars($student['lrn']) ?></td>
                            <td><?= htmlspecialchars($fullName) ?></td>
                            <td><?= htmlspecialchars(ucfirst($student['gender'])) ?></td>
                            <td><?= date('M d, Y', strtotime($student['birthdate'])) ?></td>
                            <td><?= htmlspecialchars($gradeSection) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                <i class="bi bi-search" style="font-size: 2rem; opacity: 0.3;"></i>
                                <p class="mt-2 mb-0">No students found matching your criteria</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_students > 0): ?>
    <div class="pagination-container">
        <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted">
                Page <?= $page ?> of <?= max(1, $total_pages) ?>
            </div>
            
            <nav aria-label="Page navigation">
                <ul class="pagination mb-0">
                    <!-- First Page -->
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=1&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" title="First Page">
                            <i class="bi bi-chevron-double-left"></i>
                        </a>
                    </li>
                    
                    <!-- Previous Page -->
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= max(1, $page - 1) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>">Previous</a>
                    </li>
                    
                    <!-- Page Numbers -->
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min(max(1, $total_pages), $start_page + 4);
                    $start_page = max(1, $end_page - 4);
                    
                    for($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    
                    <!-- Next Page -->
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= min($total_pages, $page + 1) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>">Next</a>
                    </li>
                    
                    <!-- Last Page -->
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= max(1, $total_pages) ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" title="Last Page">
                            <i class="bi bi-chevron-double-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <!-- Custom Page Jump -->
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">Go to:</span>
                <form method="GET" class="d-flex gap-2" onsubmit="return validatePageJump()">
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

<script>
// Preview student SF10
function previewStudent(studentId) {
    document.getElementById('hidden_student_id').value = studentId;
    document.getElementById('sf10Form').submit();
}

// Auto-dismiss alerts with fade out
document.addEventListener('DOMContentLoaded', function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
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
    
    // Search and filter functionality
    const searchInput = document.getElementById('student_search');
    const clearBtn = document.getElementById('clearStudentSearch');
    const sortSelect = document.getElementById('sortStudents');
    
    function updateTable() {
        const q = searchInput.value.trim();
        const s = sortSelect.value;
        window.location.href = `sf10_form.php?page=1&search=${encodeURIComponent(q)}&sort=${encodeURIComponent(s)}`;
    }
    
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            if (clearBtn) clearBtn.style.display = this.value ? 'block' : 'none';
            
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(updateTable, 800);
        });
    }
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            this.style.display = 'none';
            updateTable();
        });
    }
    
    if (sortSelect) {
        sortSelect.addEventListener('change', updateTable);
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
