<?php
session_start();
require_once '../includes/db.php';

$user = $_SESSION['user'] ?? null;
if (!$user || $user['role'] !== 'teacher') {
    header("Location: dashboard.php");
    exit();
}

// Get teacher's adviser assignment
$school_year_id = $_SESSION['school_year_id'];
$adviser_query = "SELECT grade_level, section FROM teacher_assignments 
                  WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ? LIMIT 1";
$stmt = $conn->prepare($adviser_query);
$stmt->bind_param("ii", $user['id'], $school_year_id);
$stmt->execute();
$adviser_result = $stmt->get_result();
$adviser_info = $adviser_result->fetch_assoc();

if (!$adviser_info) {
    $_SESSION['error'] = "You are not assigned as a class adviser.";
    header("Location: my_class.php");
    exit();
}

$grade_level = $adviser_info['grade_level'];
$section = $adviser_info['section'];
$current_school_year = $_SESSION['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

// Debug: Log the query parameters
error_log("Add Student Query - Grade: $grade_level, Section: $section, School Year: $current_school_year");

// Handle Add Students to Class (Multiple)
if (isset($_POST['add_to_class'])) {
    if (!isset($_POST['student_ids']) || empty($_POST['student_ids'])) {
        $_SESSION['error'] = "Please select at least one student.";
        header("Location: add_student_to_class.php");
        exit();
    }
    
    $student_ids = array_map('intval', $_POST['student_ids']);
    $success_count = 0;
    $error_messages = [];
    
    foreach ($student_ids as $student_id) {
    
    // Get student's current grade level
    $current_grade_query = "SELECT sa.grade_level 
                           FROM schools_attended sa 
                           WHERE sa.student_id = ? 
                           ORDER BY sa.created_at DESC 
                           LIMIT 1";
    $stmt = $conn->prepare($current_grade_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $current_grade_result = $stmt->get_result();
    $current_grade_data = $current_grade_result->fetch_assoc();
    
    // Check if student's current grade matches the class grade
    if ($current_grade_data) {
        $student_current_grade = $current_grade_data['grade_level'];
        $expected_previous_grade = $grade_level - 1;
        
        if ($student_current_grade != $expected_previous_grade && $student_current_grade != $grade_level) {
            $_SESSION['error'] = "Cannot add student: This student is currently in Grade $student_current_grade. You can only add students who completed Grade $expected_previous_grade or are transferring to Grade $grade_level.";
            header("Location: add_student_to_class.php");
            exit();
        }
    }
    
    // Check if school record already exists for this specific grade/section/year
    $check_query = "SELECT sa.id, sa.section, sa.school_year, sa.active FROM schools_attended sa
                    WHERE sa.student_id = ? AND sa.grade_level = ? AND sa.section = ? AND sa.school_year = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("iiss", $student_id, $grade_level, $section, $current_school_year);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        if ($existing['active'] == 1) {
            $_SESSION['error'] = "Cannot add student: They are already enrolled in Grade $grade_level - " . htmlspecialchars($existing['section']) . " (" . htmlspecialchars($existing['school_year']) . ").";
            header("Location: add_student_to_class.php");
            exit();
        } else {
            // Reactivate the existing record and update transfer info
            $transfer_quarters_map = $_POST['transfer_quarter'] ?? [];
            $tq_reactivate = isset($transfer_quarters_map[$student_id]) && $transfer_quarters_map[$student_id] !== '' ? (int)$transfer_quarters_map[$student_id] : null;
            $is_transfer_reactivate = ($tq_reactivate !== null) ? 1 : $existing['is_transfer'];
            $reactivate_query = "UPDATE schools_attended SET active = 1, is_transfer = ?, transfer_quarter = ? WHERE id = ?";
            $stmt = $conn->prepare($reactivate_query);
            $stmt->bind_param("iii", $is_transfer_reactivate, $tq_reactivate, $existing['id']);
            if ($stmt->execute()) {
                $success_count++;
            }
            continue; // Skip the INSERT below
        }
    }
    
    // Get user's school info
    $user_query = "SELECT school_name, school_id, district, division, region FROM users WHERE id = ?";
    $stmt = $conn->prepare($user_query);
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $user_school = $stmt->get_result()->fetch_assoc();
    
    $school_name = !empty($user_school['school_name']) ? $user_school['school_name'] : '';
    $school_id = !empty($user_school['school_id']) ? $user_school['school_id'] : '';
    $district = !empty($user_school['district']) ? $user_school['district'] : '';
    $division = !empty($user_school['division']) ? $user_school['division'] : '';
    $region = !empty($user_school['region']) ? $user_school['region'] : '';
    
    // Get transfer quarter for this specific student if provided
    $transfer_quarters_map = $_POST['transfer_quarter'] ?? [];
    $tq_val = isset($transfer_quarters_map[$student_id]) && $transfer_quarters_map[$student_id] !== '' ? (int)$transfer_quarters_map[$student_id] : null;
    $transfer_quarter = $tq_val;
    $is_transfer = ($transfer_quarter !== null) ? 1 : 0;

    // Add student to class
    $insert_query = "INSERT INTO schools_attended 
                    (student_id, school_name, school_id, district, division, region, grade_level, section, school_year, adviser_name, is_transfer, transfer_quarter)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("isssssisssii", 
        $student_id, $school_name, $school_id, $district, $division, $region,
        $grade_level, $section, $current_school_year, $user['full_name'],
        $is_transfer, $transfer_quarter
    );
    
        if ($stmt->execute()) {
            $success_count++;
        } else {
            $error_messages[] = "Failed to add student ID: $student_id";
        }
    }
    
    if ($success_count > 0) {
        $_SESSION['success'] = "Successfully added $success_count student(s) to Grade $grade_level - $section!";
    }
    if (!empty($error_messages)) {
        $_SESSION['error'] = implode('<br>', $error_messages);
    }
    
    header("Location: my_class.php");
    exit();
}

require_once '../templates/header.php';

// Get available students
$expected_previous_grade = $grade_level - 1;

// Pagination and Search variables
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = 20;
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'all';

// Fetch all eligible students first to handle pagination in memory (due to complex LEFT JOIN requirements)
$available_query = "SELECT s.id, s.lrn, s.first_name, s.last_name, s.middle_name, s.gender, s.birthdate,
                    sa_current.grade_level as current_grade,
                    sa_current.section as current_section,
                    sa_current.is_transfer as is_transfer,
                    sa_current.transfer_quarter as transfer_quarter
                    FROM students s
                    LEFT JOIN schools_attended sa_current ON s.id = sa_current.student_id 
                    AND sa_current.active = 1
                    AND sa_current.id = (
                        SELECT id FROM schools_attended 
                        WHERE student_id = s.id 
                        AND active = 1
                        AND NOT (grade_level = ? AND section = ? AND school_year = ?)
                        ORDER BY school_year DESC, grade_level DESC 
                        LIMIT 1
                    )
                    WHERE s.id NOT IN (
                        SELECT student_id FROM schools_attended 
                        WHERE grade_level = ? AND section = ? AND school_year = ? AND active = 1
                    )
                    ORDER BY s.last_name, s.first_name";
$stmt = $conn->prepare($available_query);
$stmt->bind_param("ississ", $grade_level, $section, $current_school_year, $grade_level, $section, $current_school_year);
$stmt->execute();
$all_students_result = $stmt->get_result();

$students = [];
while ($student = $all_students_result->fetch_assoc()) {
    $fullName = strtoupper($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''));
    $student['full_name_search'] = strtolower($fullName);
    $current_grade = $student['current_grade'] ?? 'New';
    $student['is_eligible'] = ($current_grade == $expected_previous_grade || $current_grade == $grade_level || $current_grade == 'New');
    $students[] = $student;
}

// Apply Filters in PHP
if (!empty($search)) {
    $search_lower = strtolower(trim($search));
    $students = array_filter($students, function($s) use ($search_lower) {
        return strpos($s['full_name_search'], $search_lower) !== false || strpos(strtolower($s['lrn']), $search_lower) !== false;
    });
}

if ($sort === 'eligible-only') {
    $students = array_filter($students, function($s) { return $s['is_eligible']; });
}

// Apply Sorting
if ($sort !== 'all' && $sort !== 'eligible-only') {
    usort($students, function($a, $b) use ($sort) {
        switch($sort) {
            case 'name-asc': return $a['full_name_search'] <=> $b['full_name_search'];
            case 'name-desc': return $b['full_name_search'] <=> $a['full_name_search'];
            case 'grade-asc': 
                $ga = $a['current_grade'] == 'New' ? 0 : (int)$a['current_grade'];
                $gb = $b['current_grade'] == 'New' ? 0 : (int)$b['current_grade'];
                return $ga <=> $gb;
        }
        return 0;
    });
}

// Pagination Logic
$total_items = count($students);
$total_pages = ceil($total_items / $items_per_page);
$page = max(1, min($total_pages, $page));
$offset = ($page - 1) * $items_per_page;
$students_to_show = array_slice($students, $offset, $items_per_page);

// Debug: Log result count
error_log("Available students count: " . $total_items);

// Handle success/error messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

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
  .pagination-container {
    padding: 10px 15px;
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    font-size: 13px;
  }
  .pagination {
    margin-bottom: 0;
  }
  .page-link {
    padding: 0.25rem 0.5rem;
    font-size: 12px;
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
  .student-row:not(.ineligible) {
    cursor: pointer;
  }
  .student-row.ineligible {
    opacity: 0.5;
    cursor: not-allowed;
  }
  .student-row.ineligible:hover {
    background-color: transparent !important;
  }
</style>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-person-plus"></i> Add Student to Class</h2>
        <p class="subtitle">Add students to Grade <?= $grade_level ?> - <?= htmlspecialchars($section) ?> (SY <?= $current_school_year ?>)</p>
        <small class="text-muted">Debug: Query uses Grade=<?= $grade_level ?>, Section=<?= htmlspecialchars($section) ?>, Year=<?= $current_school_year ?>, Count=<?= $total_items ?></small>
    </div>
    <div>
        <a href="my_class.php" class="btn btn-info">
            <i class="bi bi-arrow-left"></i> Back to My Class
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" id="errorAlert">
    <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show" id="successAlert">
    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> Only students from Grade <?= $expected_previous_grade ?> or new students can be added. Students in other grades are shown as ineligible.
</div>

<!-- Students List -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-people"></i> Available Students
            <span class="badge bg-primary ms-2" id="studentCount">0</span>
        </span>
        <div class="d-flex gap-2">
            <select id="sortStudents" class="form-select form-select-sm" style="width: auto;">
                <option value="all" <?= $sort === 'all' ? 'selected' : '' ?>>All Students</option>
                <option value="name-asc" <?= $sort === 'name-asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                <option value="name-desc" <?= $sort === 'name-desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                <option value="eligible-only" <?= $sort === 'eligible-only' ? 'selected' : '' ?>>Eligible Only</option>
                <option value="grade-asc" <?= $sort === 'grade-asc' ? 'selected' : '' ?>>Grade Level (Low-High)</option>
            </select>
            <div style="position: relative; width: 250px;">
                <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
                <input type="text" class="form-control form-control-sm" id="studentSearch" placeholder="Search by name or LRN..." value="<?= htmlspecialchars($search) ?>" style="padding-left: 35px; padding-right: 30px;">
                <button type="button" id="clearStudentSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; <?= !empty($search) ? 'display: block;' : 'display: none;' ?>">
                    <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <form method="POST" id="addStudentForm">
                <table class="table table-hover mb-0" id="studentsTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;"></th>
                            <th>LRN</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Birthdate</th>
                            <th>Current Grade</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ($students_to_show as $student): 
                            $current_grade = $student['current_grade'] ?? 'New';
                            $is_eligible = $student['is_eligible'];
                            $row_class = $is_eligible ? '' : 'ineligible';
                            $fullName = strtoupper($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''));
                            $pre_is_transfer = !empty($student['is_transfer']) && ($current_grade == $grade_level);
                            $pre_transfer_quarter = $student['transfer_quarter'] ?? null;
                        ?>
                        <tr class="student-row <?= $row_class ?>" 
                            data-student-id="<?= $student['id'] ?>"
                            data-name="<?= htmlspecialchars(strtolower($fullName)) ?>"
                            data-lrn="<?= htmlspecialchars($student['lrn']) ?>"
                            data-grade="<?= $current_grade == 'New' ? '0' : $current_grade ?>"
                            data-eligible="<?= $is_eligible ? '1' : '0' ?>">
                            <td>
                                <?php if ($is_eligible): ?>
                                <input type="checkbox" name="student_ids[]" value="<?= $student['id'] ?>" class="form-check-input student-checkbox">
                                <?php else: ?>
                                <i class="bi bi-lock text-muted"></i>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($student['lrn']) ?></td>
                            <td><?= htmlspecialchars($fullName) ?></td>
                            <td><?= htmlspecialchars($student['gender']) ?></td>
                            <td><?= date('M d, Y', strtotime($student['birthdate'])) ?></td>
                            <td>
                                <?php if ($current_grade === 'New'): ?>
                                <span class="badge bg-success">New Student</span>
                                <?php else: ?>
                                <span class="badge bg-info">Grade <?= $current_grade ?><?= !empty($student['current_section']) ? ' - ' . htmlspecialchars(ucfirst($student['current_section'])) : '' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="transfer-quarter-cell" style="min-width:160px;">
                                <?php if ($is_eligible): ?>
                                <span class="badge bg-primary me-1">Eligible</span>
                                <?php if ($pre_is_transfer): ?>
                                    <span class="enroll-type-badge badge bg-danger">Transferee<?= $pre_transfer_quarter ? ' - Q' . $pre_transfer_quarter : '' ?></span>
                                <?php else: ?>
                                    <span class="enroll-type-badge badge bg-warning text-dark">Regular</span>
                                <?php endif; ?>
                                <select name="transfer_quarter[<?= $student['id'] ?>]" class="form-select form-select-sm transfer-quarter-select mt-1" style="display:none; width:auto;" disabled>
                                    <option value="">Not Transfer</option>
                                    <option value="1" <?= $pre_transfer_quarter == 1 ? 'selected' : '' ?>>Q1</option>
                                    <option value="2" <?= $pre_transfer_quarter == 2 ? 'selected' : '' ?>>Q2</option>
                                    <option value="3" <?= $pre_transfer_quarter == 3 ? 'selected' : '' ?>>Q3</option>
                                    <option value="4" <?= $pre_transfer_quarter == 4 ? 'selected' : '' ?>>Q4</option>
                                </select>
                                <?php else: ?>
                                <span class="badge bg-secondary">Not Eligible</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students_to_show)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <i class="bi bi-info-circle" style="font-size: 3rem; color: #ccc;"></i>
                                <p class="text-muted mt-3">No available students found matching your criteria.</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php if ($total_items > 0): ?>
        <div class="pagination-container d-flex justify-content-between align-items-center">
            <div class="text-muted">
                Showing <?= $offset + 1 ?> to <?= min($offset + $items_per_page, $total_items) ?> of <?= $total_items ?> students
            </div>
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    <?php 
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&sort=<?= $sort ?>" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <div class="card-footer">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-3 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllBtn">
                        <i class="bi bi-check-square"></i> Select All Visible
                    </button>
                    <small class="text-muted"><i class="bi bi-info-circle"></i> Selected: <strong id="selectedCount">0</strong> student(s)</small>
                </div>
                <button type="submit" name="add_to_class" form="addStudentForm" class="btn btn-info" id="addToClassBtn" disabled>
                    <i class="bi bi-person-plus"></i> Add Selected to Class
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-dismiss alerts
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

// Search and filter functionality
const searchInput = document.getElementById('studentSearch');
const clearBtn = document.getElementById('clearStudentSearch');
const sortSelect = document.getElementById('sortStudents');
const tableRows = document.querySelectorAll('#studentsTable tbody .student-row');
const studentCount = document.getElementById('studentCount');
const totalItems = <?= (int)$total_items ?>;

function updateStudentCount() {
    studentCount.textContent = totalItems;
    
    if (totalItems > 0) {
        studentCount.classList.remove('bg-secondary');
        studentCount.classList.add('bg-primary');
    } else {
        studentCount.classList.remove('bg-primary');
        studentCount.classList.add('bg-secondary');
    }
}

function performSearch() {
    const searchTerm = searchInput.value.trim();
    const sortValue = sortSelect.value;
    
    // Redirect to the same page with search and sort parameters
    // This handles server-side filtering and pagination correctly
    window.location.href = `?page=1&search=${encodeURIComponent(searchTerm)}&sort=${sortValue}`;
}

// Add debouncing to search to avoid too many reloads while typing
let searchTimeout;
searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    clearBtn.style.display = this.value ? 'block' : 'none';
    searchTimeout = setTimeout(performSearch, 700);
});

clearBtn.addEventListener('click', function() {
    searchInput.value = '';
    performSearch();
});

sortSelect.addEventListener('change', performSearch);

// Initialize count
updateStudentCount();

// Row click to toggle checkbox
document.querySelector('#studentsTable tbody').addEventListener('click', function(e) {
    const row = e.target.closest('.student-row');
    if (!row || row.classList.contains('ineligible')) return;
    
    // If clicking on an interactive element (checkbox, select), don't do anything here
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'OPTION') {
        return;
    }
    
    const checkbox = row.querySelector('.student-checkbox');
    if (checkbox) {
        checkbox.checked = !checkbox.checked;
        // Trigger the change event manually since we updated it via script
        const event = new Event('change', { bubbles: true });
        checkbox.dispatchEvent(event);
    }
});

// Checkbox and select all functionality
const checkboxes = document.querySelectorAll('.student-checkbox');
const selectAllBtn = document.getElementById('selectAllBtn');
const addToClassBtn = document.getElementById('addToClassBtn');
const selectedCountSpan = document.getElementById('selectedCount');

function toggleTransferQuarter(checkbox) {
    const row = checkbox.closest('tr');
    const select = row.querySelector('.transfer-quarter-select');
    const badge = row.querySelector('.enroll-type-badge');
    if (!select) return;
    if (checkbox.checked) {
        select.style.display = 'inline-block';
        select.disabled = false;
        updateEnrollBadge(select, badge);
    } else {
        select.style.display = 'none';
        select.disabled = true;
        select.value = '';
        if (badge) { badge.className = 'enroll-type-badge badge bg-warning text-dark'; badge.textContent = 'Regular'; }
    }
}

function updateEnrollBadge(select, badge) {
    if (!badge) return;
    const val = select.value;
    if (val !== '') {
        badge.className = 'enroll-type-badge badge bg-danger';
        badge.textContent = 'Transferee - Q' + val;
    } else {
        badge.className = 'enroll-type-badge badge bg-warning text-dark';
        badge.textContent = 'Regular';
    }
}

function updateSelectedCount() {
    const checkedCount = document.querySelectorAll('.student-checkbox:checked').length;
    const visibleCheckedCount = Array.from(checkboxes).filter(cb => {
        const row = cb.closest('tr');
        return cb.checked && row && row.style.display !== 'none';
    }).length;
    
    selectedCountSpan.textContent = checkedCount;
    addToClassBtn.disabled = checkedCount === 0;
    
    // Update select all button text based on visible checkboxes
    if (selectAllBtn) {
        const visibleCheckboxes = Array.from(checkboxes).filter(cb => {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none';
        });
        
        const allVisibleChecked = visibleCheckboxes.length > 0 && visibleCheckboxes.every(cb => cb.checked);
        
        selectAllBtn.innerHTML = allVisibleChecked ? 
            '<i class="bi bi-x-square"></i> Deselect All' : 
            '<i class="bi bi-check-square"></i> Select All';
    }
}

checkboxes.forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        toggleTransferQuarter(this);
        updateSelectedCount();
    });
});

// Update badge when transfer quarter dropdown changes
document.querySelectorAll('.transfer-quarter-select').forEach(select => {
    select.addEventListener('change', function() {
        const badge = this.closest('tr').querySelector('.enroll-type-badge');
        updateEnrollBadge(this, badge);
    });
});

if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function() {
        const visibleCheckboxes = Array.from(checkboxes).filter(cb => {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none';
        });
        
        const allChecked = visibleCheckboxes.every(cb => cb.checked);
        
        visibleCheckboxes.forEach(cb => {
            cb.checked = !allChecked;
            toggleTransferQuarter(cb);
        });
        
        updateSelectedCount();
    });
}

updateSelectedCount();
</script>

<?php require_once '../templates/footer.php'; ?>
