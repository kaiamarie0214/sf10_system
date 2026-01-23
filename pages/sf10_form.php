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

require_once '../templates/header.php';
?>

<div class="page-header">
  <h2><i class="bi bi-file-earmark-spreadsheet"></i> Generate SF10 Form</h2>
  <p class="subtitle">Official DepEd SF10 Elementary 2017 Excel Template</p>
</div>

<?php if (isset($_GET['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show" id="errorAlert">
    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($_GET['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show" id="successAlert">
    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_GET['success']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header">
        <i class="bi bi-file-earmark-excel"></i> SF10 Form Generator
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> 
            <strong>Official DepEd SF10-ES Template</strong><br>
            This will generate the Learner's Permanent Academic Record using the official 
            DepEd SF10 Elementary 2017 Excel template. Click on a student name to preview their SF10 data.
        </div>

        <form action="sf10_preview.php" method="GET" id="sf10Form" style="display: none;">
            <input type="hidden" name="student_id" id="hidden_student_id">
        </form>
        
        <div class="mb-3">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" 
                       id="student_search" 
                       class="form-control" 
                       placeholder="Search by name or LRN..."
                       autocomplete="off">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="studentsTable">
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
                        data-name="<?= htmlspecialchars(strtolower($fullName)) ?>"
                        data-lrn="<?= htmlspecialchars($student['lrn']) ?>">
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
    
    // Student search functionality
    const searchInput = document.getElementById('student_search');
    const tableRows = document.querySelectorAll('#studentsTable tbody tr');
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        tableRows.forEach(row => {
            const name = row.getAttribute('data-name') || '';
            const lrn = row.getAttribute('data-lrn')?.toLowerCase() || '';
            
            if (searchTerm === '' || name.includes(searchTerm) || lrn.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});
</script>

<?php require_once '../templates/footer.php'; ?>
