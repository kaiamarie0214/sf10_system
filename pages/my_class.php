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
$school_year_id = $_SESSION['school_year_id'];
$adviser_query = "SELECT grade_level, section FROM teacher_assignments 
                  WHERE teacher_id = ? AND assignment_type = 'adviser' AND school_year_id = ? LIMIT 1";
$stmt = $conn->prepare($adviser_query);
$stmt->bind_param("ii", $user['id'], $school_year_id);
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

// Get current school year from session
$current_school_year = $_SESSION['school_year'] ?? (date('Y') . '-' . (date('Y') + 1));

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
    $check_query = "SELECT sa.id, sa.section, sa.school_year, sa.school_name FROM schools_attended sa
                    WHERE sa.student_id = ? AND sa.grade_level = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ii", $student_id, $grade_level);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    // Get the receiving teacher's school name to compare
    $user_query_check = "SELECT school_name, school_id, district, division, region FROM users WHERE id = ?";
    $stmt_check = $conn->prepare($user_query_check);
    $stmt_check->bind_param("i", $user['id']);
    $stmt_check->execute();
    $teacher_school = $stmt_check->get_result()->fetch_assoc();

    if ($existing) {
        // Allow if this is a TRANSFER — student comes from a different school and both records share same grade/year
        $is_incoming_transfer = !empty($teacher_school['school_name'])
            && trim(strtolower($existing['school_name'])) !== trim(strtolower($teacher_school['school_name']));

        // Also block if a record already exists for THIS teacher's school (already enrolled here)
        $check_same_school = "SELECT id FROM schools_attended WHERE student_id = ? AND grade_level = ? AND school_name = ?";
        $stmt_same = $conn->prepare($check_same_school);
        $stmt_same->bind_param("iis", $student_id, $grade_level, $teacher_school['school_name']);
        $stmt_same->execute();
        $same_school_record = $stmt_same->get_result()->fetch_assoc();

        if ($same_school_record) {
            $error = "Cannot add student: They are already enrolled in Grade $grade_level at your school (" . htmlspecialchars($teacher_school['school_name']) . "). A student can only be in one section per school per grade level.";
        } elseif ($is_incoming_transfer) {
            // Allow as transfer — fall through to insert below
            addStudentRecord($teacher_school, true);
        } else {
            $error = "Cannot add student: They are already enrolled in Grade $grade_level - " . htmlspecialchars($existing['section']) . " (" . htmlspecialchars($existing['school_year']) . "). If this is a transfer, make sure your school name in your profile differs from the originating school.";
        }
    } else {
        addStudentRecord($teacher_school, false);
    }
}

function addStudentRecord($user_school, $is_transfer_flag) {
    global $conn, $student_id, $grade_level, $section, $current_school_year, $user, $success, $error;

    // Use defaults if school info is empty
    $school_name = !empty($user_school['school_name']) ? $user_school['school_name'] : '';
    $school_id   = !empty($user_school['school_id'])   ? $user_school['school_id']   : '';
    $district    = !empty($user_school['district'])    ? $user_school['district']    : '';
    $division    = !empty($user_school['division'])    ? $user_school['division']    : '';
    $region      = !empty($user_school['region'])      ? $user_school['region']      : '';
    $transfer_val = $is_transfer_flag ? 1 : 0;

    // Add student to class
    $insert_query = "INSERT INTO schools_attended 
                    (student_id, school_name, school_id, district, division, region, 
                     grade_level, section, school_year, adviser_name, is_transfer) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("isssssisssi",
        $student_id,
        $school_name,
        $school_id,
        $district,
        $division,
        $region,
        $grade_level,
        $section,
        $current_school_year,
        $user['full_name'],
        $transfer_val
    );

    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $success = $is_transfer_flag
            ? "Transfer student added to your class successfully!"
            : "Student added to your class successfully!";

        // Ensure an `active` column exists so we can archive previous records.
        $col_check = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'active'");
        $has_active = ($col_check && $col_check->num_rows > 0);
        if (!$has_active) {
            $conn->query("ALTER TABLE schools_attended ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");
            $col_check = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'active'");
            $has_active = ($col_check && $col_check->num_rows > 0);
        }

        if ($has_active) {
            // Only archive OLD duplicate records for the SAME student at the SAME grade+section+school.
            // Never archive records that simply share the same school_year but different grade or section.
            if ($is_transfer_flag) {
                // Transfer: archive same-school old records for this grade only
                $archive_stmt = $conn->prepare("UPDATE schools_attended SET active = 0 WHERE student_id = ? AND id != ? AND grade_level = ? AND school_year = ? AND school_name = ?");
                $archive_stmt->bind_param("iiiss", $student_id, $new_id, $grade_level, $current_school_year, $school_name);
            } else {
                // Regular: archive any duplicate for same grade+section+school_year (not the new one)
                $archive_stmt = $conn->prepare("UPDATE schools_attended SET active = 0 WHERE student_id = ? AND id != ? AND grade_level = ? AND section = ? AND school_year = ?");
                $archive_stmt->bind_param("iiiss", $student_id, $new_id, $grade_level, $section, $current_school_year);
            }
            $archive_stmt->execute();
        }
    } else {
        $error = "Error adding student: " . $stmt->error;
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
    $class_students_query = "SELECT sa.id as school_attended_id, sa.school_year, sa.is_transfer, sa.transfer_quarter, s.*,
                         (sa.is_transfer = 1 OR EXISTS (
                             SELECT 1 FROM schools_attended sa2 
                             WHERE sa2.student_id = sa.student_id 
                             AND sa2.grade_level = sa.grade_level 
                             AND sa2.id != sa.id
                             AND LOWER(sa2.section) != LOWER(sa.section)
                         )) as is_transferee
                         FROM schools_attended sa
                         JOIN students s ON sa.student_id = s.id
                         WHERE sa.grade_level = ? 
                         AND LOWER(sa.section) = LOWER(?)
                         AND sa.school_year = ?
                         AND sa.active = 1
                         ORDER BY s.last_name, s.first_name";

    $stmt = $conn->prepare($class_students_query);
    $stmt->bind_param("iss", $grade_level, $section, $current_school_year);
} else {
    // Match on grade_level, section (case-insensitive), and school_year directly
    $class_students_query = "SELECT sa.id as school_attended_id, sa.school_year, s.*
                         FROM schools_attended sa
                         JOIN students s ON sa.student_id = s.id
                         WHERE sa.grade_level = ?
                         AND LOWER(sa.section) = LOWER(?)
                         AND sa.school_year = ?
                         ORDER BY s.last_name, s.first_name";

    $stmt = $conn->prepare($class_students_query);
    $stmt->bind_param("iss", $grade_level, $section, $current_school_year);
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
$stmt->execute();
$available_students = $stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-person-workspace"></i> My Class</h2>
        <p class="subtitle">Manage your class roster and student enrollment</p>
    </div>
    <a href="add_student_to_class.php" class="btn btn-primary">
        <i class="bi bi-person-plus"></i> Add Student to Class
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
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
                        <th>Status</th>
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
                            <?php if (!empty($student['is_transferee']) || !empty($student['is_transfer'])): ?>
                                <span class="badge bg-danger">Transferee<?= !empty($student['transfer_quarter']) ? ' - Q' . $student['transfer_quarter'] : '' ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Regular</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="input_grades_form.php?grade_level=<?= urlencode($grade_level) ?>&section=<?= urlencode($section) ?>&student_id=<?= $student['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-pencil-square"></i> Grades
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
            <i class="bi bi-info-circle"></i> No students in your class yet. <a href="add_student_to_class.php">Add students to get started</a>.
        </div>
        <?php endif; ?>
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
});
</script>

<?php require_once '../templates/footer.php'; ?>
