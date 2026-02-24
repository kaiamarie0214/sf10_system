<?php
session_start();
require_once "../includes/db.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];

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

// Special sort handling for gender
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
                LEFT JOIN (
                    SELECT student_id, MAX(id) as max_id 
                    FROM schools_attended 
                    GROUP BY student_id
                ) sa_max ON s.id = sa_max.student_id
                LEFT JOIN schools_attended sa ON sa_max.max_id = sa.id
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
$students_query = "SELECT s.id, UPPER(CONCAT(s.last_name, ', ', s.first_name)) AS fullname, s.lrn, s.gender, s.birthdate,
                          sa.grade_level, sa.section, sa.school_year
                   FROM students s
                   LEFT JOIN (
                       SELECT student_id, MAX(id) as max_id 
                       FROM schools_attended 
                       GROUP BY student_id
                   ) sa_max ON s.id = sa_max.student_id
                   LEFT JOIN schools_attended sa ON sa_max.max_id = sa.id
                   $where_sql
                   ORDER BY $order_sql
                   LIMIT ? OFFSET ?";

$stmt = $conn->prepare($students_query);
$final_types = $types . "ii";
$final_params = array_merge($params, [$items_per_page, $offset]);
$stmt->bind_param($final_types, ...$final_params);
$stmt->execute();
$students = $stmt->get_result();

include "../templates/header.php";
?>

<div class="page-header mb-4">
    <h2><i class="bi bi-clipboard-data"></i> Grade Entry</h2>
    <p class="subtitle">Enter and manage student grades</p>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<style>
  /* Full viewport layout */
  html, body {
    overflow: hidden !important;
    height: 100vh !important;
  }

  body {
    display: flex !important;
    flex-direction: column !important;
  }

  #mainContent,
  .main-wrapper#mainContent {
    flex: 1 1 auto !important;
    display: flex !important;
    flex-direction: column !important;
    min-height: 0 !important;
    overflow: hidden !important;
    padding: 20px;
  }
  
  #mainContent > *,
  .main-wrapper#mainContent > * {
    flex-shrink: 0 !important;
  }
  
  #mainContent .card:last-of-type,
  .main-wrapper#mainContent .card:last-of-type {
    flex: 1 1 auto !important;
    display: flex !important;
    flex-direction: column !important;
    min-height: 0 !important;
    overflow: hidden !important;
    margin-bottom: 0 !important;
  }
  
  #mainContent .card:last-of-type .card-body,
  .main-wrapper#mainContent .card:last-of-type .card-body {
    flex: 1 1 auto !important;
    display: flex !important;
    flex-direction: column !important;
    min-height: 0 !important;
    overflow: hidden !important;
    padding: 0 !important;
  }
  
  #mainContent .card:last-of-type .table-responsive,
  .main-wrapper#mainContent .card:last-of-type .table-responsive {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important;
    overflow-x: auto !important;
    margin-bottom: 0 !important;
    -webkit-overflow-scrolling: touch !important;
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
  
  .student-row {
    cursor: pointer;
    transition: background-color 0.2s;
  }
  
  .student-row:hover {
    background-color: rgba(13, 110, 253, 0.1);
  }

  /* Button styling */
  #studentsTable .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
  }

  /* Fix dropdown positioning on mobile */
  @media (max-width: 768px) {
    .dropdown {
      position: static !important;
    }
    
    .dropdown-menu {
      position: fixed !important;
      z-index: 10000 !important;
    }
  }

  /* Mobile responsive header controls */
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
    
    #sortStudents {
      width: 100% !important;
    }
    
    .table-responsive {
      overflow-x: auto !important;
      -webkit-overflow-scrolling: touch;
    }
    
    #studentsTable {
      min-width: 700px;
      font-size: 11px;
    }

    /* Adjust column widths for mobile */
    #studentsTable th:nth-child(1),
    #studentsTable td:nth-child(1) {
      width: 80px;
      min-width: 80px;
    }

    #studentsTable th:nth-child(2),
    #studentsTable td:nth-child(2) {
      width: 150px;
      min-width: 150px;
      max-width: 150px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    #studentsTable th:nth-child(3),
    #studentsTable td:nth-child(3) {
      width: 70px;
      min-width: 70px;
    }

    #studentsTable th:nth-child(4),
    #studentsTable td:nth-child(4) {
      width: 95px;
      min-width: 95px;
    }

    #studentsTable th:nth-child(5),
    #studentsTable td:nth-child(5) {
      width: 100px;
      min-width: 100px;
    }

    /* Adjust button size for mobile */
    #studentsTable .btn-sm {
      font-size: 0.75rem !important;
      padding: 0.25rem 0.4rem !important;
      white-space: nowrap;
    }

    #studentsTable .btn-sm i {
      font-size: 0.7rem;
    }

    /* Make action column narrower */
    #studentsTable td:last-child,
    #studentsTable th:last-child {
      width: 110px;
      min-width: 110px;
    }
  }
</style>

<!-- Students List -->
<div class="card" style="overflow: hidden; border-radius: 0.375rem;">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-people"></i> Select Student to Enter Grades
      <span class="badge bg-secondary ms-2" id="studentCount"><?= number_format($total_students) ?></span>
    </span>
    <div class="d-flex gap-2">
      <select id="sortStudents" class="form-select form-select-sm" style="width: auto;">
        <option value="name-asc" <?= $sort == 'name-asc' ? 'selected' : '' ?>>Name (A-Z)</option>
        <option value="name-desc" <?= $sort == 'name-desc' ? 'selected' : '' ?>>Name (Z-A)</option>
        <option value="grade-asc" <?= $sort == 'grade-asc' ? 'selected' : '' ?>>Grade Level (1-6)</option>
        <option value="grade-desc" <?= $sort == 'grade-desc' ? 'selected' : '' ?>>Grade Level (6-1)</option>
        <option value="gender-male" <?= $sort == 'gender-male' ? 'selected' : '' ?>>Gender (Male First)</option>
        <option value="gender-female" <?= $sort == 'gender-female' ? 'selected' : '' ?>>Gender (Female First)</option>
        <option value="filter-male" <?= $sort == 'filter-male' ? 'selected' : '' ?>>All Male</option>
        <option value="filter-female" <?= $sort == 'filter-female' ? 'selected' : '' ?>>All Female</option>
      </select>
      <div style="position: relative; width: 250px;">
        <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
        <input type="text" class="form-control form-control-sm" id="studentSearchBox" placeholder="Search by name or LRN..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 35px; padding-right: 30px;">
        <button type="button" id="clearStudentSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; <?= empty($search) ? 'display: none;' : 'display: block;' ?>">
          <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
        </button>
      </div>
    </div>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="studentsTable">
        <thead>
          <tr>
            <th>LRN</th>
            <th>Name</th>
            <th>Gender</th>
            <th>Birthdate</th>
            <th>Grade/Section</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($students->num_rows > 0): ?>
            <?php while($student = $students->fetch_assoc()): ?>
            <tr class="student-row" onclick="enterGrades(<?= $student['id'] ?>, '<?= htmlspecialchars($student['fullname'], ENT_QUOTES) ?>')">
              <td><?= htmlspecialchars($student['lrn']) ?></td>
              <td>
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars(strtoupper($student['fullname'])) ?>
              </td>
              <td><?= htmlspecialchars($student['gender'] ?? 'N/A') ?></td>
              <td><?= !empty($student['birthdate']) ? date('M d, Y', strtotime($student['birthdate'])) : 'N/A' ?></td>
              <td>
                <?php if ($student['grade_level']): ?>
                  <span class="badge bg-primary">Grade <?= htmlspecialchars($student['grade_level'] . ' - ' . ($student['section'] ?? '-')) ?></span>
                <?php else: ?>
                  <span class="badge bg-secondary">No records</span>
                <?php endif; ?>
              </td>
              <td>
                <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); enterGrades(<?= $student['id'] ?>, '<?= htmlspecialchars($student['fullname'], ENT_QUOTES) ?>')">
                  <i class="bi bi-pencil-square"></i> Enter Grades
                </button>
              </td>
            </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="text-center py-4 text-muted">
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
// Student list management
document.addEventListener('DOMContentLoaded', function() {
  const searchBox = document.getElementById('studentSearchBox');
  const clearBtn = document.getElementById('clearStudentSearch');
  const sortSelect = document.getElementById('sortStudents');
  
  function updateTable() {
    const q = searchBox.value.trim();
    const s = sortSelect.value;
    window.location.href = `grade_entry.php?page=1&search=${encodeURIComponent(q)}&sort=${encodeURIComponent(s)}`;
  }
  
  // Search functionality
  if (searchBox) {
    let searchTimeout;
    searchBox.addEventListener('input', function() {
      if (clearBtn) clearBtn.style.display = this.value ? 'inline-block' : 'none';
      
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(updateTable, 800);
    });
  }
  
  // Clear search
  if (clearBtn) {
    clearBtn.addEventListener('click', function() {
      searchBox.value = '';
      this.style.display = 'none';
      updateTable();
    });
  }
  
  // Sort functionality
  if (sortSelect) {
    sortSelect.addEventListener('change', updateTable);
  }
  
  // Auto-hide alerts
  const alerts = document.querySelectorAll('.alert');
  alerts.forEach(alert => {
    setTimeout(() => {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
      if (bsAlert) bsAlert.close();
    }, 5000);
  });
});

// Enter grades function
function enterGrades(studentId, studentName) {
  window.location.href = `enter_grades.php?student_id=${studentId}&student_name=${encodeURIComponent(studentName)}`;
}
</script>

<?php include "../templates/footer.php"; ?>
