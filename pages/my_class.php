<?php
require_once '../includes/db.php';
require_once '../templates/header.php';

$user = $_SESSION['user'];
if ($user['role'] !== 'teacher') {
    header("Location: dashboard.php");
    exit();
}

$success = "";
$error = "";

// Get teacher's adviser assignment
$adviser_query = "SELECT grade_level, section FROM teacher_assignments 
                  WHERE teacher_id = ? AND assignment_type = 'adviser' LIMIT 1";
$stmt = $conn->prepare($adviser_query);
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$adviser_result = $stmt->get_result();
$adviser_info = $adviser_result->fetch_assoc();

if (!$adviser_info) {
    echo '<div class="container-fluid mt-4">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i> You are not assigned as a class adviser. Please contact the administrator.
            </div>
          </div>';
    require_once '../templates/footer.php';
    exit();
}

$grade_level = $adviser_info['grade_level'];
$section = $adviser_info['section'];

// Get current school year (you can make this dynamic)
$current_school_year = date('Y') . '-' . (date('Y') + 1);

// Handle Add Student to Class
if (isset($_POST['add_to_class'])) {
    $student_id = (int)$_POST['student_id'];
    
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
        
        // If student is not in the expected grade (previous grade), show error
        $expected_previous_grade = $grade_level - 1;
        if ($student_current_grade != $expected_previous_grade && $student_current_grade != $grade_level) {
            $error = "Cannot add student: This student is currently in Grade $student_current_grade. You can only add students who completed Grade $expected_previous_grade or are transferring to Grade $grade_level.";
        } else {
            // Continue with existing validation
            checkAndAddStudent();
        }
    } else {
        // Student has no grade history, allow adding
        checkAndAddStudent();
    }
}

function checkAndAddStudent() {
    global $conn, $student_id, $grade_level, $section, $current_school_year, $user, $success, $error;
    
    // Check if school record already exists for this grade level and school year (ANY school year, ANY section)
    $check_query = "SELECT sa.id, sa.section, sa.school_year FROM schools_attended sa
                    WHERE sa.student_id = ? AND sa.grade_level = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ii", $student_id, $grade_level);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        $error = "Cannot add student: They are already enrolled in Grade $grade_level - " . htmlspecialchars($existing['section']) . " (" . htmlspecialchars($existing['school_year']) . "). A student can only be in one section per grade level. Please remove them from the other section first.";
    } else {
        // Get user's school info
        $user_query = "SELECT school_name, school_id, district, division, region FROM users WHERE id = ?";
        $stmt = $conn->prepare($user_query);
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $user_school = $stmt->get_result()->fetch_assoc();
        
        // Use defaults if school info is empty
        $school_name = !empty($user_school['school_name']) ? $user_school['school_name'] : '';
        $school_id = !empty($user_school['school_id']) ? $user_school['school_id'] : '';
        $district = !empty($user_school['district']) ? $user_school['district'] : '';
        $division = !empty($user_school['division']) ? $user_school['division'] : '';
        $region = !empty($user_school['region']) ? $user_school['region'] : '';
        
        // Add student to class
        $insert_query = "INSERT INTO schools_attended 
                        (student_id, school_name, school_id, district, division, region, 
                         grade_level, section, school_year, adviser_name, is_transfer) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("isssssisss", 
            $student_id,
            $school_name,
            $school_id,
            $district,
            $division,
            $region,
            $grade_level,
            $section,
            $current_school_year,
            $user['full_name']
        );
        
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $success = "Student added to your class successfully!";

            // Ensure an `active` column exists so we can archive previous records.
            // If it does not exist, try to add it (requires ALTER TABLE privileges).
            $col_check = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'active'");
            $has_active = ($col_check && $col_check->num_rows > 0);
            if (!$has_active) {
                // Attempt to add the column safely; ignore errors if permission denied.
                $conn->query("ALTER TABLE schools_attended ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");
                // Re-check
                $col_check = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'active'");
                $has_active = ($col_check && $col_check->num_rows > 0);
            }

            if ($has_active) {
                $archive_stmt = $conn->prepare("UPDATE schools_attended SET active = 0 WHERE student_id = ? AND id != ? AND school_year = ?");
                $archive_stmt->bind_param("iis", $student_id, $new_id, $current_school_year);
                $archive_stmt->execute();
            }
        } else {
            $error = "Error adding student: " . $stmt->error;
        }
    }
}

// Handle Remove Student from Class
if (isset($_POST['remove_from_class'])) {
    $school_attended_id = (int)$_POST['school_attended_id'];
    // Prefer marking the record inactive so historical grades remain accessible
    $col_check = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'active'");
    $has_active = ($col_check && $col_check->num_rows > 0);

    if ($has_active) {
        $update_query = "UPDATE schools_attended SET active = 0 WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $school_attended_id);

        if ($stmt->execute()) {
            $success = "Student removed from your class (record archived).";
        } else {
            $error = "Error archiving student record: " . $stmt->error;
        }
    } else {
        // Fallback for older schemas: delete the record
        $delete_query = "DELETE FROM schools_attended WHERE id = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("i", $school_attended_id);

        if ($stmt->execute()) {
            $success = "Student removed from your class.";
        } else {
            $error = "Error removing student: " . $stmt->error;
        }
    }
}

// Get students in this class
$col_check = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'active'");
$has_active = ($col_check && $col_check->num_rows > 0);

if ($has_active) {
    $class_students_query = "SELECT sa.id as school_attended_id, sa.school_year, s.*
                         FROM schools_attended sa
                         JOIN students s ON sa.student_id = s.id
                         WHERE sa.grade_level = ? 
                         AND sa.section = ? 
                         AND sa.school_year = ?
                         AND sa.active = 1
                         ORDER BY s.last_name, s.first_name";

    $stmt = $conn->prepare($class_students_query);
    $stmt->bind_param("iss", $grade_level, $section, $current_school_year);
} else {
    // Fallback for schemas without `active`: only consider the latest schools_attended record per student (by max id)
    $class_students_query = "SELECT sa.id as school_attended_id, sa.school_year, s.*
                         FROM schools_attended sa
                         JOIN students s ON sa.student_id = s.id
                         WHERE sa.school_year = ?
                         AND sa.id IN (SELECT MAX(id) FROM schools_attended WHERE school_year = ? GROUP BY student_id)
                         AND sa.grade_level = ?
                         AND sa.section = ?
                         ORDER BY s.last_name, s.first_name";

    $stmt = $conn->prepare($class_students_query);
    $stmt->bind_param("ssis", $current_school_year, $current_school_year, $grade_level, $section);
}

$stmt->execute();
$class_students = $stmt->get_result();

// Get all students NOT in this grade level for this school year (any section)
// Also get their current grade level
if ($has_active) {
    $not_in_clause = "(SELECT DISTINCT student_id FROM schools_attended WHERE grade_level = ? AND school_year = ? AND active = 1)";

    $available_students_query = "SELECT s.*, 
                             (SELECT sa.grade_level 
                              FROM schools_attended sa 
                              WHERE sa.student_id = s.id 
                              ORDER BY sa.created_at DESC 
                              LIMIT 1) as current_grade
                             FROM students s
                             WHERE s.id NOT IN ($not_in_clause)
                             ORDER BY s.last_name, s.first_name";

    $stmt = $conn->prepare($available_students_query);
    $stmt->bind_param("is", $grade_level, $current_school_year);
} else {
    // Exclude students whose LATEST schools_attended record for the school year shows them in this grade
    $available_students_query = "SELECT s.*, 
                             (SELECT sa.grade_level FROM schools_attended sa WHERE sa.student_id = s.id ORDER BY sa.id DESC LIMIT 1) as current_grade
                             FROM students s
                             WHERE s.id NOT IN (
                                 SELECT sa.student_id FROM schools_attended sa
                                 WHERE sa.school_year = ?
                                 AND sa.id IN (SELECT MAX(id) FROM schools_attended WHERE school_year = ? GROUP BY student_id)
                                 AND sa.grade_level = ?
                             )
                             ORDER BY s.last_name, s.first_name";

    $stmt = $conn->prepare($available_students_query);
    $stmt->bind_param("ssi", $current_school_year, $current_school_year, $grade_level);
}
$stmt->bind_param("is", $grade_level, $current_school_year);
$stmt->execute();
$available_students = $stmt->get_result();
?>

<div class="page-header">
  <h2><i class="bi bi-person-workspace"></i> My Class</h2>
  <p class="subtitle">Manage your class roster and student enrollment</p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Class Info -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="bi bi-clipboard-check"></i> Grade <?= $grade_level ?> - <?= htmlspecialchars($section) ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <strong>Grade Level:</strong> <?= $grade_level ?>
                </div>
                <div class="col-md-3">
                    <strong>Section:</strong> <?= htmlspecialchars($section) ?>
                </div>
                <div class="col-md-3">
                    <strong>School Year:</strong> <?= $current_school_year ?>
                </div>
                <div class="col-md-3">
                    <strong>Total Students:</strong> <?= $class_students->num_rows ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Student Button -->
    <div class="mb-3">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStudentModal">
            <i class="bi bi-person-plus"></i> Add Student to Class
        </button>
    </div>

    <!-- Students in Class -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-people-fill"></i> Students in My Class
        </div>
        <div class="card-body">
            <?php if ($class_students->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>LRN</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Birthdate</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($student = $class_students->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($student['lrn']) ?></td>
                            <td><?= htmlspecialchars(strtoupper($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars($student['gender']) ?></td>
                            <td><?= date('M d, Y', strtotime($student['birthdate'])) ?></td>
                            <td>
                                <a href="grades.php?student_id=<?= $student['id'] ?>&school_attended_id=<?= $student['school_attended_id'] ?>&school_year=<?= urlencode($student['school_year']) ?>&open_new_tab=1" class="btn btn-sm btn-primary">
                                    <i class="bi bi-journal-text"></i> Grades
                                </a>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#removeModal"
                                        data-student-id="<?= $student['school_attended_id'] ?>"
                                        data-student-name="<?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?>">
                                    <i class="bi bi-person-dash"></i> Remove
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No students in your class yet. Click "Add Student to Class" to get started.
            </div>
            <?php endif; ?>
        </div>
    </div>

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg" style="margin-top: 80px;">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Student to Grade <?= $grade_level ?> - <?= htmlspecialchars($section) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div class="mb-3">
                        <label class="form-label">Select Student <span class="text-danger">*</span></label>
                        <div class="mb-2">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="addStudentSearch" class="form-control" placeholder="Search by LRN or name...">
                                <select id="studentSortOrder" class="form-select" style="max-width: 150px;">
                                    <option value="asc">A to Z</option>
                                    <option value="desc">Z to A</option>
                                </select>
                            </div>
                        </div>
                        <select name="student_id" id="studentSelectDropdown" class="form-control" size="10" style="height: 300px;" required>
                            <option value="">-- Select Student --</option>
                            <?php 
                            $count = 0;
                            $expected_previous_grade = $grade_level - 1;
                            while ($student = $available_students->fetch_assoc()): 
                                $count++;
                                $current_grade = $student['current_grade'] ?? 'New';
                                
                                // Check if student is eligible (should be from previous grade or new student)
                                $is_eligible = ($current_grade == $expected_previous_grade || $current_grade == 'New');
                                $option_class = !$is_eligible ? 'text-muted' : '';
                                $disabled = !$is_eligible ? 'disabled' : '';
                            ?>
                            <option value="<?= $student['id'] ?>" 
                                    class="<?= $option_class ?>" 
                                    <?= $disabled ?>
                                    data-lrn="<?= htmlspecialchars($student['lrn']) ?>"
                                    data-name="<?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?>"
                                    data-grade="<?= $current_grade ?>"
                                    data-eligible="<?= $is_eligible ? '1' : '0' ?>">
                                <?= htmlspecialchars($student['lrn']) ?> - 
                                <?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?> 
                                (Grade <?= $current_grade ?>)
                                <?php if (!$is_eligible): ?>
                                    - Not eligible
                                <?php endif; ?>
                            </option>
                            <?php endwhile; ?>
                            <?php if ($count == 0): ?>
                            <option value="" disabled>No available students (all students in Grade <?= $grade_level ?> are already enrolled)</option>
                            <?php endif; ?>
                        </select>
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i> Only students from Grade <?= $expected_previous_grade ?> or new students can be added. 
                            <span class="text-danger">Students in other grades are disabled and shown in gray.</span>
                        </small>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> The student will be added to Grade <?= $grade_level ?> - <?= htmlspecialchars($section) ?> for school year <?= $current_school_year ?> with you as the adviser.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_to_class" class="btn btn-success">
                        <i class="bi bi-person-plus"></i> Add to Class
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove Student Modal -->
<div class="modal fade" id="removeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Remove Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="school_attended_id" id="removeStudentId">
                    <p>Are you sure you want to remove <strong id="removeStudentName"></strong> from your class?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle"></i> This will remove the student from Grade <?= $grade_level ?> - <?= htmlspecialchars($section) ?>. This action cannot be undone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="remove_from_class" class="btn btn-danger">
                        <i class="bi bi-person-dash"></i> Remove Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Handle remove modal data
document.addEventListener('DOMContentLoaded', function() {
    const removeModal = document.getElementById('removeModal');
    removeModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const studentId = button.getAttribute('data-student-id');
        const studentName = button.getAttribute('data-student-name');
        
        document.getElementById('removeStudentId').value = studentId;
        document.getElementById('removeStudentName').textContent = studentName;
    });

    // Add Student Modal - Search and Sort functionality
    const addStudentSearch = document.getElementById('addStudentSearch');
    const studentSortOrder = document.getElementById('studentSortOrder');
    const studentSelectDropdown = document.getElementById('studentSelectDropdown');
    
    // Get all options except the first one (placeholder)
    let allOptions = Array.from(studentSelectDropdown.options).slice(1);
    
    // Search functionality
    if (addStudentSearch) {
        addStudentSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            
            // Get current sort order
            const currentSort = studentSortOrder.value;
            
            // Filter options
            const filteredOptions = allOptions.filter(option => {
                const lrn = option.getAttribute('data-lrn').toLowerCase();
                const name = option.getAttribute('data-name').toLowerCase();
                return lrn.includes(searchTerm) || name.includes(searchTerm);
            });
            
            // Sort filtered options
            sortOptions(filteredOptions, currentSort);
            
            // Update dropdown
            updateDropdown(filteredOptions);
        });
    }
    
    // Sort functionality
    if (studentSortOrder) {
        studentSortOrder.addEventListener('change', function() {
            const searchTerm = addStudentSearch.value.toLowerCase();
            
            // Filter options based on search
            const filteredOptions = allOptions.filter(option => {
                if (!searchTerm) return true;
                const lrn = option.getAttribute('data-lrn').toLowerCase();
                const name = option.getAttribute('data-name').toLowerCase();
                return lrn.includes(searchTerm) || name.includes(searchTerm);
            });
            
            // Sort filtered options
            sortOptions(filteredOptions, this.value);
            
            // Update dropdown
            updateDropdown(filteredOptions);
        });
    }
    
    // Helper function to sort options
    function sortOptions(options, order) {
        options.sort((a, b) => {
            const nameA = a.getAttribute('data-name').toLowerCase();
            const nameB = b.getAttribute('data-name').toLowerCase();
            
            if (order === 'asc') {
                return nameA.localeCompare(nameB);
            } else {
                return nameB.localeCompare(nameA);
            }
        });
    }
    
    // Helper function to update dropdown
    function updateDropdown(options) {
        // Clear all options except the first placeholder
        while (studentSelectDropdown.options.length > 1) {
            studentSelectDropdown.remove(1);
        }
        
        // Add filtered and sorted options
        options.forEach(option => {
            studentSelectDropdown.add(option.cloneNode(true));
        });
    }
    
    // Initial sort on page load (A to Z by default)
    sortOptions(allOptions, 'asc');
    updateDropdown(allOptions);
});
</script>

<?php require_once '../templates/footer.php'; ?>
