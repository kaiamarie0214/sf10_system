<?php
session_start();
require_once "../includes/db.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];

// Get all students
$students_query = "SELECT DISTINCT s.id, UPPER(CONCAT(s.last_name, ', ', s.first_name)) AS fullname, s.lrn, s.gender, s.birthdate
                   FROM students s
                   ORDER BY s.last_name, s.first_name";
$students = $conn->query($students_query);

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
      </select>
      <div style="position: relative; width: 250px;">
        <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
        <input type="text" class="form-control form-control-sm" id="studentSearchBox" placeholder="Search by name or LRN..." style="padding-left: 35px; padding-right: 30px;">
        <button type="button" id="clearStudentSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; display: none;">
          <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
        </button>
      </div>
    </div>
  </div>
  <div class="card-body" style="height: 500px; overflow: hidden; padding: 0; border-radius: 0 0 0.375rem 0.375rem;">
    <div class="table-responsive" style="height: 100%; overflow-y: auto; overflow-x: auto; border-radius: 0 0 0.375rem 0.375rem;">
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
          <?php 
          $students->data_seek(0);
          while($student = $students->fetch_assoc()): 
            // Get latest school record
            $school_query = $conn->prepare("SELECT grade_level, section, school_year FROM schools_attended 
                                             WHERE student_id = ? 
                                             ORDER BY grade_level DESC, school_year DESC LIMIT 1");
            $school_query->bind_param("i", $student['id']);
            $school_query->execute();
            $school_result = $school_query->get_result();
            $school_data = $school_result->fetch_assoc();
          ?>
          <tr class="student-row" 
              data-student-id="<?= $student['id'] ?>"
              data-name="<?= htmlspecialchars($student['fullname']) ?>"
              data-lrn="<?= htmlspecialchars($student['lrn']) ?>"
              data-gender="<?= htmlspecialchars($student['gender'] ?? '') ?>"
              data-grade="<?= $school_data['grade_level'] ?? '0' ?>">
            <td><?= htmlspecialchars($student['lrn']) ?></td>
            <td>
              <i class="bi bi-person-circle"></i> <?= htmlspecialchars(strtoupper($student['fullname'])) ?>
            </td>
            <td><?= htmlspecialchars($student['gender'] ?? 'N/A') ?></td>
            <td><?= !empty($student['birthdate']) ? date('M d, Y', strtotime($student['birthdate'])) : 'N/A' ?></td>
            <td>
              <?php if ($school_data): ?>
                <span class="badge bg-primary">Grade <?= htmlspecialchars($school_data['grade_level'] . ' - ' . ($school_data['section'] ?? '-')) ?></span>
              <?php else: ?>
                <span class="badge bg-secondary">No records</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-sm btn-primary" onclick="enterGrades(<?= $student['id'] ?>, '<?= htmlspecialchars($student['fullname'], ENT_QUOTES) ?>')">
                <i class="bi bi-pencil-square"></i> Enter Grades
              </button>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Student list management
document.addEventListener('DOMContentLoaded', function() {
  const searchBox = document.getElementById('studentSearchBox');
  const clearBtn = document.getElementById('clearStudentSearch');
  const sortSelect = document.getElementById('sortStudents');
  
  // Update student count
  function updateStudentCount() {
    const rows = Array.from(document.querySelectorAll('.student-row'));
    const visible = rows.filter(r => r.style.display !== 'none').length;
    const badge = document.getElementById('studentCount');
    badge.textContent = visible;
    badge.className = 'badge ms-2 ' + (visible > 0 ? 'bg-primary' : 'bg-secondary');
  }
  
  // Search functionality
  if (searchBox) {
    searchBox.addEventListener('input', function() {
      if (clearBtn) clearBtn.style.display = this.value ? 'inline-block' : 'none';
      const q = this.value.toLowerCase();
      document.querySelectorAll('.student-row').forEach(row => {
        const name = row.getAttribute('data-name').toLowerCase();
        const lrn = row.getAttribute('data-lrn').toLowerCase();
        row.style.display = (name.includes(q) || lrn.includes(q)) ? '' : 'none';
      });
      updateStudentCount();
    });
  }
  
  // Clear search
  if (clearBtn) {
    clearBtn.addEventListener('click', function() {
      searchBox.value = '';
      this.style.display = 'none';
      document.querySelectorAll('.student-row').forEach(row => row.style.display = '');
      updateStudentCount();
    });
  }
  
  // Sort functionality
  if (sortSelect) {
    sortSelect.addEventListener('change', function() {
      const tbody = document.querySelector('#studentsTable tbody');
      const rows = Array.from(document.querySelectorAll('.student-row'));
      
      rows.sort((a, b) => {
        const value = this.value;
        
        if (value === 'name-asc') {
          return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
        } else if (value === 'name-desc') {
          return b.getAttribute('data-name').localeCompare(a.getAttribute('data-name'));
        } else if (value === 'grade-asc') {
          return parseInt(a.getAttribute('data-grade')) - parseInt(b.getAttribute('data-grade'));
        } else if (value === 'grade-desc') {
          return parseInt(b.getAttribute('data-grade')) - parseInt(a.getAttribute('data-grade'));
        } else if (value === 'gender-male') {
          const genderA = a.getAttribute('data-gender');
          const genderB = b.getAttribute('data-gender');
          if (genderA === 'Male' && genderB !== 'Male') return -1;
          if (genderA !== 'Male' && genderB === 'Male') return 1;
          return 0;
        } else if (value === 'gender-female') {
          const genderA = a.getAttribute('data-gender');
          const genderB = b.getAttribute('data-gender');
          if (genderA === 'Female' && genderB !== 'Female') return -1;
          if (genderA !== 'Female' && genderB === 'Female') return 1;
          return 0;
        }
        return 0;
      });
      
      rows.forEach(row => tbody.appendChild(row));
    });
  }
  
  // Initialize count
  updateStudentCount();
  
  // Auto-hide alerts
  const alerts = document.querySelectorAll('.alert');
  alerts.forEach(alert => {
    setTimeout(() => {
      const bsAlert = new bootstrap.Alert(alert);
      bsAlert.close();
    }, 5000);
  });
});

// Enter grades function
function enterGrades(studentId, studentName) {
  // Redirect to enter_grades.php with student_id
  window.location.href = `enter_grades.php?student_id=${studentId}&student_name=${encodeURIComponent(studentName)}`;
}
</script>

<?php include "../templates/footer.php"; ?>
