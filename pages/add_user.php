<?php
session_start();
include "../includes/db.php";

// Check if user is logged in and is admin
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];
if ($user['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// Set current page for sidebar navigation
$_SERVER['PHP_SELF'] = 'users.php';

$success = "";
$error = "";

// Get classes (grade levels and sections) for dropdowns
$classes_query = "SELECT DISTINCT grade_level, section, school_year, status 
                  FROM classes 
                  WHERE status = 'Active' 
                  ORDER BY grade_level, section";
$classes_result = $conn->query($classes_query);
$classes_data = [];
while ($class = $classes_result->fetch_assoc()) {
    $classes_data[] = $class;
}

// Subjects will be loaded dynamically via AJAX based on selected grade level

// AJAX endpoint to get subjects for a specific grade level
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_grade_subjects') {
    header('Content-Type: application/json');
    $grade = (int)$_GET['grade'];
    
    if ($grade < 1 || $grade > 6) {
        echo json_encode(['subjects' => []]);
        exit;
    }
    
    // Get all subjects
    $all_subjects = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY id");
    $subjects_list = [];
    
    while ($subject = $all_subjects->fetch_assoc()) {
        // Check if there's a customized name for this grade level
        $grade_query = $conn->query("SELECT subject_name FROM subject_grade_groups 
                                     WHERE grade_level = $grade AND subject_id = {$subject['id']}");
        
        $display_name = $subject['subject_name'];
        if ($grade_query && $grade_query->num_rows > 0) {
            $grade_data = $grade_query->fetch_assoc();
            $display_name = $grade_data['subject_name'];
        }
        
        $subjects_list[] = [
            'id' => $subject['id'],
            'name' => $display_name
        ];
    }
    
    echo json_encode(['subjects' => $subjects_list]);
    exit;
}

// AJAX endpoint to check if section already has an adviser
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_adviser') {
    header('Content-Type: application/json');
    $grade = (int)$_GET['grade'];
    $section = $_GET['section'];
    $school_year_id = $_SESSION['school_year_id'];
    
    $result = ['has_adviser' => false, 'adviser_name' => null];
    
    $check_query = $conn->prepare("SELECT u.full_name 
                                   FROM teacher_assignments ta
                                   JOIN users u ON ta.teacher_id = u.id
                                   WHERE ta.grade_level = ? 
                                   AND ta.section = ? 
                                   AND ta.assignment_type = 'adviser'
                                   AND ta.school_year_id = ?");
    $check_query->bind_param("isi", $grade, $section, $school_year_id);
    $check_query->execute();
    $check_result = $check_query->get_result();
    
    if ($adviser = $check_result->fetch_assoc()) {
        $result['has_adviser'] = true;
        $result['adviser_name'] = $adviser['full_name'];
    }
    
    echo json_encode($result);
    exit;
}

// Handle Add User
if (isset($_POST['add_user'])) {
    try {
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        // Get school information (nullable)
        $school_name = !empty($_POST['school_name']) ? $_POST['school_name'] : null;
        $school_id = !empty($_POST['school_id']) ? $_POST['school_id'] : null;
        $district = !empty($_POST['district']) ? $_POST['district'] : null;
        $division = !empty($_POST['division']) ? $_POST['division'] : null;
        $region = !empty($_POST['region']) ? $_POST['region'] : null;
        
        // Basic user creation with school info
        $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, school_name, school_id, district, division, region, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssssss", $_POST['username'], $password, $_POST['full_name'], $_POST['role'], 
                          $school_name, $school_id, $district, $division, $region);
        
        if ($stmt->execute()) {
            $new_user_id = $conn->insert_id;
            
            // If teacher, handle assignments
            if ($_POST['role'] === 'teacher') {
                $school_year_id = $_SESSION['school_year_id'];
                $school_year = $_SESSION['school_year'];
                
                // Add adviser assignment if checked
                if (isset($_POST['is_adviser']) && $_POST['is_adviser'] == '1' && !empty($_POST['adviser_class'])) {
                    // adviser_class format: "grade_level|section"
                    list($grade, $section) = explode('|', $_POST['adviser_class']);
                    $grade = (int)$grade;
                    
                    $stmt_adv = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, grade_level, section, school_year_id, school_year) 
                                                VALUES (?, 'adviser', ?, ?, ?, ?)");
                    $stmt_adv->bind_param("iisis", $new_user_id, $grade, $section, $school_year_id, $school_year);
                    $stmt_adv->execute();
                }
                
                // Add subject assignments if provided
                if (isset($_POST['subject_assignments']) && is_array($_POST['subject_assignments'])) {
                    foreach ($_POST['subject_assignments'] as $assignment) {
                        if (!empty($assignment['subject_id']) && !empty($assignment['grade']) && !empty($assignment['sections'])) {
                            $subject_id = (int)$assignment['subject_id'];
                            $grade = (int)$assignment['grade'];
                            
                            // Insert for each selected section
                            foreach ($assignment['sections'] as $section) {
                                $stmt_subj = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, assignment_type, subject_id, grade_level, section, school_year_id, school_year) 
                                                            VALUES (?, 'subject', ?, ?, ?, ?, ?)");
                                $stmt_subj->bind_param("iiisis", $new_user_id, $subject_id, $grade, $section, $school_year_id, $school_year);
                                $stmt_subj->execute();
                            }
                        }
                    }
                }
            }
            
            $_SESSION['success_message'] = "User added successfully!";
            header("Location: users.php");
            exit;
        } else {
            $error = "Error adding user: " . $stmt->error;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

include "../templates/header.php";
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-person-plus-fill"></i> Add New User</h2>
        <p class="subtitle">Create a new system user with role and assignments</p>
    </div>
    <a href="users.php" class="btn btn-info">
        <i class="bi bi-arrow-left"></i> Back to Users
    </a>
</div>

<?php if (!empty($error)): ?>
    <div class='alert alert-danger alert-dismissible fade show' role='alert' id='errorAlert'>
        <i class="bi bi-exclamation-circle"></i> <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<script>
// Auto-dismiss alerts with fade out
document.addEventListener('DOMContentLoaded', function() {
    // Submit form on Enter key from any input/select (except textarea)
    document.getElementById('addUserForm').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            this.submit();
        }
    });

    const errorAlert = document.getElementById('errorAlert');
    
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.transition = 'opacity 0.5s ease-out';
            errorAlert.style.opacity = '0';
            setTimeout(() => errorAlert.remove(), 500);
        }, 7000);
    }
});
</script>

<!-- Add User Form -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-person-badge"></i> User Information
    </div>
    <div class="card-body">
        <form method="POST" id="addUserForm">
            <!-- Basic Info -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" autocomplete="off" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" autocomplete="off" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-control" id="roleSelect" onchange="toggleTeacherFields()" required>
                        <option value="">-- Select Role --</option>
                        <option value="admin">Admin</option>
                        <option value="teacher">Teacher</option>
                    </select>
                </div>
            </div>
            
            <!-- School Information -->
            <hr>
            <h6 class="mb-3"><i class="bi bi-building"></i> School Information</h6>
            <div class="row mb-3">
                <div class="col-md-8">
                    <label class="form-label">School Name</label>
                    <input type="text" name="school_name" class="form-control" autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label">School ID</label>
                    <input type="text" name="school_id" class="form-control" autocomplete="off">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">District</label>
                    <input type="text" name="district" class="form-control" autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Division</label>
                    <input type="text" name="division" class="form-control" autocomplete="off">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Region</label>
                    <input type="text" name="region" class="form-control" autocomplete="off">
                </div>
            </div>
            
            <!-- Teacher Assignments Section -->
            <div class="teacher-fields d-none">
                <hr>
                <h6 class="mb-3"><i class="bi bi-person-badge"></i> Teacher Assignments</h6>
                
                <!-- Adviser Assignment -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_adviser" value="1" id="isAdviserCheck" onchange="toggleAdviserFields()">
                            <label class="form-check-label" for="isAdviserCheck">
                                <strong>This teacher is a Class Adviser</strong>
                            </label>
                        </div>
                        <div class="adviser-fields d-none">
                            <div class="mb-3">
                                <label class="form-label">Select Class to Advise <span class="text-danger">*</span></label>
                                <select name="adviser_class" id="adviserClassSelect" class="form-control" onchange="checkExistingAdviser()">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($classes_data as $class): ?>
                                        <option value="<?= $class['grade_level'] ?>|<?= htmlspecialchars($class['section']) ?>">
                                            Grade <?= $class['grade_level'] ?> - <?= htmlspecialchars($class['section']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Select from existing classes in the system</small>
                                <div id="adviserWarning" class="alert alert-warning mt-2" style="display: none; border-left: 4px solid #ff6b6b; background-color: #fff3cd; padding: 10px;">
                                    <i class="bi bi-exclamation-triangle-fill text-danger"></i> 
                                    <strong id="adviserWarningText"></strong>
                                    <br>
                                    <small>This class already has an adviser. Adding this user will reassign the class and remove the previous adviser.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Subject Assignments -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="hasSubjectsCheck" onchange="toggleSubjectFields()">
                            <label class="form-check-label" for="hasSubjectsCheck">
                                <strong>This teacher has Subject Assignments</strong>
                            </label>
                        </div>
                        <div class="subject-fields d-none">
                            <div class="d-flex justify-content-between align-items-center mb-3 pb-2">
                                <strong><i class="bi bi-book"></i> Subject Assignments</strong>
                                <button type="button" class="btn btn-sm btn-success" onclick="addSubjectRow()">
                                    <i class="bi bi-plus-circle"></i> Add Subject
                                </button>
                            </div>
                            <div id="subjectAssignmentsContainer">
                                <p class="text-muted text-center mb-0">
                                    <i class="bi bi-info-circle"></i> Click "Add Subject" to assign this teacher to subjects
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="users.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" name="add_user" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Add User
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let subjectRowCounter = 0;

// Get classes data (grade levels and sections)
const classesData = <?= json_encode($classes_data) ?>;

// Cache for subjects by grade level
const subjectsByGrade = {};

function toggleTeacherFields() {
    const role = document.getElementById('roleSelect').value;
    document.querySelector('.teacher-fields').classList.toggle('d-none', role !== 'teacher');
}

function toggleAdviserFields() {
    const isAdviser = document.getElementById('isAdviserCheck').checked;
    document.querySelector('.adviser-fields').classList.toggle('d-none', !isAdviser);
}

function toggleSubjectFields() {
    const hasSubjects = document.getElementById('hasSubjectsCheck').checked;
    document.querySelector('.subject-fields').classList.toggle('d-none', !hasSubjects);
    
    // Clear subject assignments if unchecked
    if (!hasSubjects) {
        const container = document.getElementById('subjectAssignmentsContainer');
        container.innerHTML = '<p class="text-muted text-center mb-0"><i class="bi bi-info-circle"></i> Click "Add Subject" to assign this teacher to subjects</p>';
    }
}

function addSubjectRow() {
    subjectRowCounter++;
    const container = document.getElementById('subjectAssignmentsContainer');
    
    // Remove "no subjects" message if present
    const emptyMsg = container.querySelector('p.text-muted');
    if (emptyMsg) emptyMsg.remove();
    
    const row = document.createElement('div');
    row.className = 'subject-row border rounded p-3 mb-3 bg-light';
    row.id = 'subjectRow' + subjectRowCounter;
    
    let gradeOptions = '<option value="">-- Select Grade --</option>';
    let uniqueGrades = [...new Set(classesData.map(c => c.grade_level))].sort((a, b) => a - b);
    uniqueGrades.forEach(grade => {
        gradeOptions += `<option value="${grade}">Grade ${grade}</option>`;
    });
    
    row.innerHTML = `
        <div class="row mb-2">
            <div class="col-md-4">
                <label class="form-label"><strong>Grade Level</strong></label>
                <select name="subject_assignments[${subjectRowCounter}][grade]" 
                        id="gradeSelect${subjectRowCounter}"
                        class="form-control" 
                        onchange="loadSubjectsForRow(${subjectRowCounter})" 
                        required>
                    ${gradeOptions}
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><strong>Subject</strong></label>
                <select name="subject_assignments[${subjectRowCounter}][subject_id]" 
                        id="subjectSelect${subjectRowCounter}"
                        class="form-control" 
                        disabled
                        required>
                    <option value="">-- Select Grade First --</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-danger btn-sm w-100" onclick="removeSubjectRow(${subjectRowCounter})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <label class="form-label"><strong>Sections</strong></label>
                <div id="sectionsContainer${subjectRowCounter}" class="border rounded p-3 bg-white" style="min-height: 60px;">
                    <p class="text-muted mb-0"><i class="bi bi-arrow-up"></i> Select a grade level first</p>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(row);
}

function loadSubjectsForRow(rowId) {
    const gradeSelect = document.getElementById('gradeSelect' + rowId);
    const subjectSelect = document.getElementById('subjectSelect' + rowId);
    const selectedGrade = gradeSelect.value;
    
    if (!selectedGrade) {
        subjectSelect.innerHTML = '<option value="">-- Select Grade First --</option>';
        subjectSelect.disabled = true;
        return;
    }
    
    // Check cache first
    if (subjectsByGrade[selectedGrade]) {
        populateSubjectDropdown(rowId, subjectsByGrade[selectedGrade]);
        updateSections(rowId);
        return;
    }
    
    // Fetch subjects for this grade level
    subjectSelect.innerHTML = '<option value="">Loading...</option>';
    subjectSelect.disabled = true;
    
    fetch(`add_user.php?ajax=get_grade_subjects&grade=${selectedGrade}`)
        .then(response => response.json())
        .then(data => {
            subjectsByGrade[selectedGrade] = data.subjects;
            populateSubjectDropdown(rowId, data.subjects);
            updateSections(rowId);
        })
        .catch(error => {
            console.error('Error loading subjects:', error);
            subjectSelect.innerHTML = '<option value="">Error loading subjects</option>';
        });
}

function populateSubjectDropdown(rowId, subjects) {
    const subjectSelect = document.getElementById('subjectSelect' + rowId);
    let options = '<option value="">-- Select Subject --</option>';
    
    subjects.forEach(subject => {
        options += `<option value="${subject.id}">${subject.name}</option>`;
    });
    
    subjectSelect.innerHTML = options;
    subjectSelect.disabled = false;
}

function updateSections(rowId) {
    const gradeSelect = document.getElementById('gradeSelect' + rowId);
    const sectionsContainer = document.getElementById('sectionsContainer' + rowId);
    const selectedGrade = gradeSelect.value;
    
    if (!selectedGrade) {
        sectionsContainer.innerHTML = '<p class="text-muted mb-0"><i class="bi bi-arrow-up"></i> Select a grade level first</p>';
        return;
    }
    
    const gradeSections = classesData.filter(c => c.grade_level == selectedGrade);
    
    if (gradeSections.length === 0) {
        sectionsContainer.innerHTML = '<p class="text-warning mb-0"><i class="bi bi-exclamation-triangle"></i> No sections found for this grade.</p>';
        return;
    }
    
    let checkboxesHtml = '<div class="d-flex flex-wrap gap-3">';
    gradeSections.forEach(cls => {
        const sectionId = `sec${rowId}_${cls.section.replace(/\s+/g, '_')}`;
        checkboxesHtml += `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" 
                       name="subject_assignments[${rowId}][sections][]" 
                       value="${cls.section}" 
                       id="${sectionId}">
                <label class="form-check-label" for="${sectionId}">${cls.section}</label>
            </div>
        `;
    });
    checkboxesHtml += '</div>';
    
    sectionsContainer.innerHTML = checkboxesHtml;
}

function removeSubjectRow(id) {
    const row = document.getElementById('subjectRow' + id);
    row.remove();
    
    // Show empty message if no rows left
    const container = document.getElementById('subjectAssignmentsContainer');
    if (container.children.length === 0) {
        container.innerHTML = '<p class="text-muted text-center mb-0"><i class="bi bi-info-circle"></i> Click "Add Subject" to assign this teacher to subjects</p>';
    }
}

// Check if selected section already has an adviser
function checkExistingAdviser() {
    const dropdown = document.getElementById('adviserClassSelect');
    const warningDiv = document.getElementById('adviserWarning');
    const warningText = document.getElementById('adviserWarningText');
    const selectedValue = dropdown.value;
    
    if (!selectedValue) {
        // No selection - hide warning
        warningDiv.style.display = 'none';
        dropdown.classList.remove('border-danger');
        return;
    }
    
    const [grade, section] = selectedValue.split('|');
    
    fetch(`add_user.php?ajax=check_adviser&grade=${grade}&section=${encodeURIComponent(section)}`)
        .then(response => response.json())
        .then(data => {
            if (data.has_adviser) {
                // Show warning with red border
                dropdown.classList.add('border-danger');
                warningText.textContent = `Grade ${grade} - ${section} is currently assigned to "${data.adviser_name}".`;
                warningDiv.style.display = 'block';
                
                // Add glow effect
                dropdown.style.boxShadow = '0 0 10px rgba(255, 107, 107, 0.5)';
            } else {
                // No conflict - hide warning
                dropdown.classList.remove('border-danger');
                dropdown.style.boxShadow = '';
                warningDiv.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error checking adviser:', error);
        });
}
</script>

<?php include '../templates/footer.php'; ?>
