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

// Get all students with their current grade/section from latest school record
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
                   ORDER BY s.last_name, s.first_name";
$students = $conn->query($students_query);

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
</style>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-file-earmark-excel"></i> SF10 Form Generator
            <span class="badge bg-secondary ms-2" id="studentCount">0</span>
        </span>
        <div class="d-flex gap-2">
            <select id="sortStudents" class="form-select form-select-sm" style="width: auto;">
                <option value="all">All Students</option>
                <option value="name-asc">Name (A-Z)</option>
                <option value="name-desc">Name (Z-A)</option>
                <option value="grade-asc">Grade Level (1-6)</option>
                <option value="grade-desc">Grade Level (6-1)</option>
                <option value="gender-male">Gender (Male First)</option>
                <option value="gender-female">Gender (Female First)</option>
                <option value="filter-male">All Male</option>
                <option value="filter-female">All Female</option>
            </select>
            <div style="position: relative; width: 250px;">
                <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
                <input type="text" class="form-control form-control-sm" id="student_search" placeholder="Search by name or LRN..." style="padding-left: 35px; padding-right: 30px;">
                <button type="button" id="clearStudentSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; display: none;">
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
                    <?php while ($student = $students->fetch_assoc()): 
                        $fullName = strtoupper($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''));
                        $gradeSection = $student['grade_level'] 
                            ? $student['grade_level'] . ($student['section'] ? ' - ' . $student['section'] : '') 
                            : 'N/A';
                    ?>
                    <tr style="cursor: pointer;" 
                        onclick="previewStudent(<?= $student['id'] ?>)"
                        class="student-row"
                        data-name="<?= htmlspecialchars(strtolower($fullName)) ?>"
                        data-lrn="<?= htmlspecialchars($student['lrn']) ?>"
                        data-gender="<?= htmlspecialchars(strtolower($student['gender'])) ?>"
                        data-grade="<?= $student['grade_level'] ?? '0' ?>">
                        <td><?= htmlspecialchars($student['lrn']) ?></td>
                        <td><?= htmlspecialchars($fullName) ?></td>
                        <td><?= htmlspecialchars(ucfirst($student['gender'])) ?></td>
                        <td><?= date('M d, Y', strtotime($student['birthdate'])) ?></td>
                        <td><?= htmlspecialchars($gradeSection) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
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
    const tableRows = document.querySelectorAll('#studentsTable tbody .student-row');
    const studentCount = document.getElementById('studentCount');
    
    function updateStudentCount() {
        const visibleRows = Array.from(tableRows).filter(row => row.style.display !== 'none');
        const count = visibleRows.length;
        studentCount.textContent = count;
        
        // Change badge color based on count
        if (count > 0) {
            studentCount.classList.remove('bg-secondary');
            studentCount.classList.add('bg-primary');
        } else {
            studentCount.classList.remove('bg-primary');
            studentCount.classList.add('bg-secondary');
        }
    }
    
    function performSearch() {
        const searchTerm = searchInput.value.toLowerCase().trim();
        const sortValue = document.getElementById('sortStudents').value;
        
        let visibleRows = [];
        
        tableRows.forEach(row => {
            const name = row.getAttribute('data-name') || '';
            const lrn = row.getAttribute('data-lrn')?.toLowerCase() || '';
            const gender = row.getAttribute('data-gender')?.toLowerCase() || '';
            
            // Apply filter first
            let matchesFilter = true;
            if (sortValue === 'filter-male' && gender !== 'male') {
                matchesFilter = false;
            } else if (sortValue === 'filter-female' && gender !== 'female') {
                matchesFilter = false;
            }
            
            // Apply search
            const matchesSearch = searchTerm === '' || name.includes(searchTerm) || lrn.includes(searchTerm);
            
            if (matchesFilter && matchesSearch) {
                row.style.display = '';
                visibleRows.push(row);
            } else {
                row.style.display = 'none';
            }
        });
        
        // Apply sorting to visible rows
        if (sortValue && sortValue !== 'all' && !sortValue.startsWith('filter-')) {
            visibleRows.sort((a, b) => {
                const nameA = a.getAttribute('data-name') || '';
                const nameB = b.getAttribute('data-name') || '';
                const gradeA = parseInt(a.getAttribute('data-grade')) || 0;
                const gradeB = parseInt(b.getAttribute('data-grade')) || 0;
                const genderA = a.getAttribute('data-gender') || '';
                const genderB = b.getAttribute('data-gender') || '';
                
                switch(sortValue) {
                    case 'name-asc':
                        return nameA.localeCompare(nameB);
                    case 'name-desc':
                        return nameB.localeCompare(nameA);
                    case 'grade-asc':
                        return gradeA - gradeB;
                    case 'grade-desc':
                        return gradeB - gradeA;
                    case 'gender-male':
                        if (genderA === 'male' && genderB !== 'male') return -1;
                        if (genderA !== 'male' && genderB === 'male') return 1;
                        return nameA.localeCompare(nameB);
                    case 'gender-female':
                        if (genderA === 'female' && genderB !== 'female') return -1;
                        if (genderA !== 'female' && genderB === 'female') return 1;
                        return nameA.localeCompare(nameB);
                }
                return 0;
            });
            
            const tbody = document.querySelector('#studentsTable tbody');
            visibleRows.forEach(row => tbody.appendChild(row));
        }
        
        clearBtn.style.display = searchTerm ? 'block' : 'none';
        updateStudentCount();
    }
    
    searchInput.addEventListener('input', performSearch);
    
    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        performSearch();
        searchInput.focus();
    });
    
    // Sorting functionality
    document.getElementById('sortStudents').addEventListener('change', performSearch);
    
    // Initialize count on page load
    updateStudentCount();
});
</script>

<?php require_once '../templates/footer.php'; ?>
